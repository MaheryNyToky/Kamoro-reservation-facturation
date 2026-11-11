# Audit de performance — Kamoro

Date : 2026-09-28
Périmètre : client Flutter Web, API Laravel, SQLite, moteur IA FastAPI et livraison Nginx/Docker.
Nature : audit initial du code et des artefacts locaux, puis optimisations et mesures sur l’environnement local. Aucune mesure de production n’a été faite.

## Optimisations implémentées — 2026-09-28

- Pagination API optionnelle (`per_page`, `page`) avec taille plafonnée à 100. La liste de réception charge 50 lignes à la fois et demande les suivantes au scroll. Le format tableau historique demeure disponible aux autres clients.
- Recherche et type de réservation filtrés côté API pour que les résultats restent complets malgré la pagination. La recherche Flutter est temporisée de 350 ms ; l’écran ne refiltre et ne retrie plus la collection entière à chaque build.
- Le récapitulatif des paiements demande seulement les séjours dont la date de départ est dans la période choisie ; le changement de période relance le chargement et utilise un cache local indexé par période.
- L’index d’occupation lit les lignes chambre-réservation directement, tient compte des dates de segment et met en cache son résultat par version/date/période. L’historique destiné au moteur IA est également conservé en cache par version cinq minutes.
- Le résumé IA accumule les revenus pendant le parcours des nuits effectivement occupées, au lieu de parcourir toutes les réservations pour chaque jour demandé.

### Mesures locales après changement

Mesures HTTP sur la base locale de développement, qui contient 1 620 réservations et 2 608 liens chambre-réservation. Elles ne remplacent pas des mesures en production. Le point de comparaison « complet » conserve le contrat non paginé et est mesuré dans le même serveur/build ; il donne une comparaison de volume exploitable, mais aucun chronométrage avant modification n’avait été capturé.

| Requête | Temps mesuré | Corps reçu | Résultat |
|---|---:|---:|---|
| Historique complet, appel chaud | 27 ms | 3 890 296 octets | 1 620 réservations |
| Page de 50, appel chaud | 10 ms | 129 019 octets | 50 réservations, total paginé |
| Récapitulatif, départs du 1 au 30 juin 2026 | 44 ms | 147 441 octets | 40 réservations |
| Prédictions 30 jours, appel froid | 30 ms | 54 445 octets | HTTP 200, mode fallback, moteur IA indisponible |
| Prédictions 30 jours, appel chaud | 9 ms | 54 445 octets | Cache Laravel |

La page de 50 lignes réduit le payload de 96,7 % par rapport à l’historique complet ; le filtre mensuel du récapitulatif le réduit de 96,2 %. Les appels froids et chauds sont indiqués séparément, et l’environnement local n’avait pas le moteur IA disponible : la latence réseau réelle de prédiction reste à mesurer.

### Vérification

- `ReservationListingOptimizationTest`, `YieldServiceTest`, `BookingConflictTest`, `SegmentedReservationFlowTest` : 20 tests passés, 96 assertions.
- `payment_summary_filter_test.dart` : 4 tests passés.
- Analyse Dart des deux écrans modifiés : aucune anomalie.
- Syntaxe PHP vérifiée pour le contrôleur et les trois services modifiés.

## Synthèse et priorités

Les problèmes les mieux étayés touchent les gros volumes transférés et travaillés dans une requête interactive, ainsi que le calcul des prédictions. Le dépôt n’apporte pas de mesures de latence ou de volumétrie représentatives : l’impact est donc classé selon le coût structurel observé et doit être confirmé par instrumentation.

| Rang | Constat actuel | Impact potentiel | Confiance |
|---|---|---|---|
| P0 | `/api/reservations/all?date=all` charge et sérialise toute la collection avec relations associées | DB, mémoire PHP, payload, décodage et cache local Flutter croissent avec l’historique | Élevée |
| P0 | Une prédiction reconstruit l’historique jour par jour puis effectue un appel HTTP IA synchrone | Latence de l’endpoint, CPU/mémoire PHP, attente réseau et risque de timeout | Élevée sur le chemin, inconnue sur fréquence réelle |
| P1 | L’index d’occupation et les revenus IA matérialisent les réservations en PHP et bouclent sur nuits × chambres | CPU et mémoire croissants avec les séjours et la période | Élevée |
| P1 | L’écran de réservations trie et filtre la liste complète à chaque build | CPU UI et allocations proportionnels au nombre de réservations | Élevée pour le travail effectué, impact perceptible à mesurer |
| P1 | Filtrage par recherche dans l’écran principal limité au nom client, après téléchargement des réservations | Mauvaise pertinence fonctionnelle et transfert/traitement superflu | Élevée |
| P1 | Build web livré : `main.dart.js` 4,0 Mo et répertoire total 42 Mo, dont CanvasKit 37 Mo | Démarrage à froid et transfert selon moteur/chemin sélectionné | Élevée sur tailles, inconnue sur bytes réellement téléchargés |
| P2 | Recherches SQL avec `LOWER(...) LIKE '%...%'` et dates calculées via `COALESCE(...)` | Scans possibles et index B-tree peu efficaces | Moyenne à élevée, plan SQLite non mesuré |
| P2 | Cache disque Laravel et cache d’écran SharedPreferences, sans hit rate ni coût de sérialisation mesurés | Travail évité contre I/O disque, JSON et stockage local | Moyenne |
| P3 | Logo applicatif PNG de 184 Ko | Gain de chargement limité au regard du bundle/runtime | Élevée sur taille, faible impact relatif |

## Constats et preuves

### P0 — Liste complète de réservations

`HotelManagementController::getAllReservations()` (`hestiapredict/app/Http/Controllers/HotelManagementController.php`) appelle `reservationsForDate()`. Pour `date=all`, `BookingService::reservationsForDate()` ne pose aucune borne de date et termine par `get()` (`hestiapredict/app/Services/BookingService.php`). Il précharge chambres, utilisateurs, clients, organisations, factures, articles, paiements, factures enfants et plusieurs audits, puis formate chaque modèle. La réponse complète est mise en cache 30 secondes.

Le client appelle cet endpoint depuis `_fetchReservations()` et conserve une copie JSON complète dans SharedPreferences. L’écran fait ensuite filtre/recherche/tri sur cette liste entière. Ce chemin est structurellement coûteux dès que l’historique augmente. Les demandes `date=all` doivent être vérifiées dans le parcours d’édition et les autres écrans, et le volume réel des payloads doit être relevé avant de choisir pagination, projection minimale ou endpoint de détail.

### P0 — Calcul des prédictions dans le chemin HTTP

`getAiPredictionsAndPricing()` cache le résultat cinq minutes par version/date/période. À l’expiration ou après invalidation, `YieldService::predictions()` (`hestiapredict/app/Services/YieldService.php`) réalise, dans une requête Laravel :

1. `historyData()` charge les lignes actives de `booking_room`, les joint à réservations et chambres, puis développe en PHP chaque segment en une entrée par nuit ;
2. `occupancyIndexForPeriod()` charge réservations et chambres puis remplit un index par nuit et catégorie ;
3. Guzzle appelle le moteur IA avec timeout de connexion 1 s et timeout total 3 s ;
4. en cas d’échec, le fallback effectue encore des calculs locaux.

Le dashboard appelle les prédictions au démarrage. L’écran de réservation a également une requête `/api/dashboard/predictions?days=30` lors du chargement IA ; le garde local vise cinq minutes, mais il ne supprime pas le travail des sessions simultanées et n’est pas partagé entre clients. Le cache Laravel réduit la fréquence par clé, toutefois les changements de disponibilité incrémentent la version de cache et peuvent rendre une clé froide. La cadence effective dépend donc des modifications et du nombre d’utilisateurs.

### P1 — Parcours récurrents et traitements CPU

`AvailabilityService::occupancyIndexForPeriod()` fait un parcours réservation × nuit × chambre. `YieldService::aiRevenueSummary()` parcourt ensuite, pour chaque jour demandé, toutes les réservations et leurs chambres. Cela ne constitue pas un N+1 SQL actuel (la sélection de réservations est unique avec eager loading), mais un coût CPU en mémoire qui augmente avec la période, les séjours et le nombre de chambres.

Les réservations segmentées ont une logique différente des dates de réservation globales. `historyData()` emploie les dates de segments via `COALESCE`, tandis que `occupancyIndexForPeriod()` travaille actuellement sur `check_in_date` et `check_out_date` de la réservation et ses chambres ; vérifier l’écart fonctionnel/performance avec des séjours segmentés avant toute refonte.

### P1 — Recherche, tri et rebuilds Flutter

Dans `ReservationsListPage.build()` (`hestia_app/lib/screens/reservations_list_page.dart`), chaque rebuild filtre `_reservations`, transforme le nom en minuscules, trie toute la collection par dates et construit le résultat avant le `ListView.builder`. La liste visuelle est paresseuse, mais le filtre et le tri ne le sont pas. L’`onChanged` de la recherche déclenche `setState` pour chaque changement de texte et le filtre local ne teste que `client_name`.

Dans le dashboard, `Timer.periodic` interroge toutes les 10 secondes deux endpoints parallèles (`live-availability` et `reservation-status-summary`). Le garde `_isAvailabilityFetching` évite le chevauchement côté écran. Une exécution regroupe les changements reçus dans un `setState`; le coût de rebuild réel dépend de l’arbre Flutter et n’est pas mesuré. Les prédictions sont demandées séparément au démarrage et ne font pas partie du timer actuel.

### P1 — Bundle, renderer et ressources

Les artefacts disponibles dans `hestia_app/build/web` indiquent : `main.dart.js` 4,0 Mo, répertoire complet 42 Mo, sous-répertoire CanvasKit 37 Mo et logo livré 184 Ko. Le dossier CanvasKit contient plusieurs variantes wasm/JS et fichiers `.symbols`. Ces tailles locales ne sont pas une mesure du téléchargement réel : le renderer sélectionné, le chargement différé, la compression gzip activée dans Nginx et le cache navigateur déterminent les octets transférés.

Nginx a une configuration déjà favorable pour le cache : assets js/css/images/fonts/wasm à un an `immutable`, JSON à une heure, et `index.html` sans cache. Gzip est activé. En revanche, Docker construit avec `--pwa-strategy=none`, et `index.html` exécute un script qui désenregistre les service workers existants et efface les caches du navigateur. Cela empêche la réutilisation hors cache HTTP par un ancien service worker ; l’effet exact doit être validé dans le navigateur cible. Ne pas classer le cache statique Nginx comme problème actuel.

### P2 — Requêtes et index

Les recherches de clients/organisations/réservations contiennent des expressions `LOWER(colonne) LIKE '%terme%'` (`ClientController`, `OrganizationController`, `HotelManagementController`). Un index B-tree ordinaire est généralement peu utile avec fonction et joker initial. Les disponibilités et l’historique IA filtrent des dates calculées par `COALESCE(booking_room.segment_*, reservations.check_*)`. Les migrations d’index existent, notamment des index récents sur tables d’audit/factures, mais aucun `EXPLAIN QUERY PLAN` n’a été exécuté. Le dépôt n’embarque pas de base de production utilisable comme échantillon.

### P2 — Stratégie de cache

Docker utilise `CACHE_STORE=file`. Les caches Laravel évitent de recalculer plusieurs réponses, mais sont stockés sur disque. Le client met aussi la liste entière des réservations en SharedPreferences et effectue encode/decode JSON. Aucun hit rate, volume des fichiers, taille JSON ou durée de sérialisation n’est exposé dans les éléments inspectés. Les clés basées sur un compteur de version peuvent laisser les anciennes entrées expirer naturellement plutôt que les supprimer immédiatement ; l’espace occupé n’a pas été mesuré.

### N+1 et pistes écartées

Le N+1 historique de `activeReservations()` n’est plus confirmé : le code courant appelle une fois `precomputeVisitCountsForReservations()` pour l’ensemble des réservations actives, qui fait une requête groupée des candidats et précharge factures/paiements. `visitCountForReservation()` subsiste mais n’est pas appelé par ce chemin. Ne pas conserver l’ancien constat N+1 comme problème actuel.

Le résumé IA par jour, mentionné dans un audit antérieur comme une requête par jour, a lui aussi été corrigé : `aiRevenueSummary()` effectue une seule requête de période avec `with('rooms')`, puis travaille en mémoire. Le coût CPU décrit plus haut reste pertinent.

Le cache des assets Nginx est maintenant long et immuable ; la compression gzip est activée. L’image principale pèse 184 Ko : optimisable, mais sans priorité par rapport aux payloads métiers ou au runtime web. Aucune preuve de jank précise ou de dépendance PDF dominante dans le bundle n’a été collectée.

## Ce qui reste inconnu

- p50/p95 et latence par endpoint, taux d’erreur, concurrence et TTFB ;
- nombre de réservations, chambres-réservation, audits et paiements en production ;
- taille et temps de sérialisation des réponses, surtout `date=all` ;
- plans SQLite et index effectivement choisis ;
- proportion de cache hit et effet d’invalidation ;
- temps jusqu’au premier écran utilisable, renderer réellement choisi, octets transférés froid/chaud ;
- coût de parsing JSON et temps de frame/rebuild sur matériel cible.

Aucune de ces valeurs n’est supposée ou inventée dans cet audit.

## Plan de mesure avant toute correction

1. Instrumenter temporairement Laravel en environnement de profiling : endpoint, durée totale, nombre de requêtes, temps DB cumulé, mémoire max et taille de réponse. Garder une trace distincte des appels réseau IA. Ne pas activer le logging SQL détaillé en production.
2. Sur une copie représentative de la base, relever les cardinalités et lancer `EXPLAIN QUERY PLAN` sur les listes, recherches, disponibilités segmentées, `historyData()` et résumé de statuts.
3. Comparer `reservations/all` pour une date, `date=all`, un ID seul, et les filtres de statut : p50/p95, lignes lues, nombre de relations, bytes JSON et mémoire PHP.
4. Mesurer `/dashboard/predictions` avec cache chaud/froid, moteur IA disponible/indisponible, période 30 et 365 jours, et plusieurs requêtes simultanées. Séparer temps DB/calcul local/HTTP IA.
5. Sur le web release de production, mesurer cache froid puis chaud : premier rendu utile, ressources réellement chargées, transfert compressé, parse/compile JS, renderer, coût de chargement WASM. Enregistrer un profil DevTools Flutter pour la liste longue pendant recherche et scrolling.
6. Établir des budgets p50/p95 avec l’équipe et le matériel cible avant de sélectionner les changements.

## Ordre recommandé après mesures

1. Réduire le chemin des réservations : pagination/filtrage serveur et endpoint ciblé pour les détails, après vérification des usages qui exigent un historique complet.
2. Isoler et mutualiser les prédictions : cache/dédoublonnage partagé, invalidation moins large et recalcul asynchrone si l’expérience métier l’autorise ; ne pas diminuer la fraîcheur sans validation métier.
3. Réduire le calcul d’occupation et du résumé à partir des profils : agrégation SQL ou index compact, en préservant les segments de chambre.
4. Éviter le filtre/tri global à chaque build Flutter : debounce de recherche et dérivation des résultats uniquement quand les données/filtres changent ; valider par profilage avant refactorisation des widgets.
5. Décider des index/recherche après plans SQLite ; tester aussi le coût d’écriture et les requêtes de chevauchement des dates.
6. Mesurer le chargement du renderer et des ressources avant d’ajuster CanvasKit, WASM ou service worker. Le cache HTTP et gzip sont déjà configurés.
7. Ne traiter image et dépendances qu’après comparaison du transfert réellement observé.

Chaque correction devra être comparée sur le même jeu de données avec nombre de requêtes, p50/p95, temps DB/IA, mémoire, payload, cold/warm load et mesures UI. Aucun changement de code applicatif n’a été réalisé dans le cadre de cet audit.
