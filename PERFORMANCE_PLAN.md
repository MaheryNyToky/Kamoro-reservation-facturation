# Audit de performance — Kamoro

Date de l’audit : 2026-09-19  
Périmètre : application Flutter (web/native), API Laravel, SQLite, moteur IA HTTP et livraison Docker/Nginx.

## Synthèse

Les risques les plus importants ne sont pas les widgets Flutter isolés ni l’image du logo. Ils se trouvent dans le chemin dashboard → API : une même actualisation déclenche trois requêtes, dont un calcul IA coûteux, puis cette séquence est répétée toutes les 10 secondes. Côté API, plusieurs endpoints chargent des collections complètes et des relations lourdes ; `activeReservations()` contient en plus un N+1 de type O(nombre de réservations actives × nombre total de réservations).

Le dépôt ne contient pas de base SQLite de production exploitable pour un benchmark, et aucun serveur applicatif n’était lancé pendant l’audit. Les niveaux « confirmé statiquement » ci-dessous sont donc fondés sur le code et les artefacts présents ; les latences, volumes réels et plans SQLite doivent être confirmés avec instrumentation sur un jeu de données représentatif.

## Priorités

| Priorité | Problème | Impact attendu | Confiance |
|---|---|---:|---|
| P0 | Le dashboard appelle `/predictions` à chaque rafraîchissement de 10 s | CPU/DB/réseau/IA, concurrence et latence utilisateur | Élevée |
| P0 | `activeReservations()` recalculera tout l’historique pour chaque ligne | Explosion du nombre de requêtes et du temps serveur | Élevée |
| P0 | Les endpoints de réservations renvoient une collection non paginée avec relations lourdes | Temps de réponse, mémoire PHP, taille JSON et parsing Flutter | Élevée |
| P1 | `aiRevenueSummary()` exécute une requête de réservations par jour | Jusqu’à N requêtes pour N jours, en plus du calcul prédictions | Élevée |
| P1 | Le rafraîchissement du dashboard provoque plusieurs `setState` et appels concurrents | Rebuilds inutiles, réponses hors ordre, pression réseau | Élevée |
| P1 | Les filtres de recherche utilisent `LOWER(...) LIKE '%term%'` et `COALESCE(...)` | Index peu ou pas exploitables quand les tables grossissent | Élevée |
| P1 | Assets web désactivés du cache et build Flutter monolithique | Chargement initial et rechargements très coûteux | Élevée |
| P2 | Calculs synchrones en mémoire sur périodes, réservations et JSON | Pics CPU et jank sur longues périodes/volumes | Moyenne à élevée |
| P2 | Cache file/database et invalidation par version non mesurés | Hits moins rapides, accumulation possible de clés obsolètes | Moyenne |
| P3 | Logo non optimisé et dépendances PDF/printing chargées dans le bundle | Gain limité face à CanvasKit et `main.dart.js` | Élevée |

## Constats détaillés et preuves

### P0 — prédictions IA déclenchées par le polling

`StaffDashboard.initState()` appelle `_fetchLiveAvailability()` puis programme un `Timer.periodic` de 10 secondes (`hestia_app/lib/main.dart:481-488`). Chaque exécution appelle :

1. `/api/live-availability` ;
2. `/api/dashboard/reservation-status-summary` ;
3. `/api/dashboard/predictions?days=30` (`main.dart:504-567`).

Le troisième appel ne dépend pas du changement d’occupation immédiat et n’est pas annulé ou dédoublonné. Il exécute `YieldService::predictions()` : lecture de tout l’historique `booking_room`, construction d’un index d’occupation sur 30 jours, puis appel HTTP au moteur IA avec timeout de 3 secondes (`hestiapredict/app/Services/YieldService.php:20-54`). En cas d’échec, le fallback reconstruit encore les résultats en mémoire.

Effet probable : six requêtes dashboard par minute et six appels IA par minute et par session, auxquels s’ajoutent des lectures SQLite et des allocations JSON. Plusieurs exécutions peuvent se chevaucher si une requête dépasse 10 secondes. C’est le bottleneck prioritaire.

### P0 — N+1 dans les réservations actives

`BookingService::activeReservations()` charge les réservations actives puis appelle `visitCountForReservation()` pour chaque résultat (`hestiapredict/app/Services/BookingService.php:1090-1117`). Cette méthode exécute à chaque fois :

```php
Reservation::query()->with(['invoice', 'invoice.payments'])->get()
```

Puis elle filtre tout l’historique en PHP (`BookingService.php:1119-1150`). Pour A réservations actives et N réservations historiques, le coût est au minimum A lectures complètes et A parcours complets, avec répétition des relations invoice/payments. Il faut remplacer ce calcul par un regroupement préchargé ou une agrégation SQL unique avant toute optimisation mineure.

### P0 — réponses de réservations trop larges et non paginées

`reservationsForDate()` et `activeReservations()` font un `get()` sans pagination (`BookingService.php:1046-1107`). Ils eager-loadent notamment `rooms`, `audits` (tous les audits), `invoice.items`, `invoice.payments`, utilisateur, client et organisation, puis sérialisent chaque réservation via `formatReservation()`.

Le client utilise en plus `/api/reservations/all?date=all` pour rafraîchir une seule réservation dans l’écran d’édition (`hestia_app/lib/screens/reservations_list_page.dart:223-265`). Cela transfère et décode tout le dataset au lieu de demander l’ID ciblé. Le même endpoint est utilisé pour la liste principale (`reservations_list_page.dart:1503-1553`). L’impact combine DB, mémoire PHP, taille de réponse, `json.decode` sur l’isolate UI et stockage SharedPreferences.

### P1 — requêtes par jour dans le résumé de revenus IA

`YieldService::aiRevenueSummary()` appelle d’abord `predictions()`, puis lance une requête `Reservation ... ->with('rooms')->get()` dans une boucle de jours (`YieldService.php:86-120`). Le nombre de requêtes croît linéairement avec `days` ; le dashboard prévoit déjà 30 jours. Le calcul peut être alimenté par un seul jeu de réservations chevauchant la période, puis indexé en mémoire ou agrégé par jour côté SQL.

### P1 — recherches et prédicats qui limitent les index

Les recherches d’historique appliquent `LOWER(colonne) LIKE '%term%'` sur plusieurs colonnes et `orWhereHas` sur `guest` et `organization` (`HotelManagementController.php:601-700`). Les wildcards en tête et les fonctions sur colonne empêchent généralement un index B-tree classique d’être utilisé efficacement. Les requêtes de disponibilité utilisent `COALESCE(booking_room.segment_start_date, reservations.check_in_date)` et la même expression pour la fin de segment (`AvailabilityService.php:167-179` et `40-60`). Les migrations ont de bons index de base sur statuts/dates et sur `booking_room`, mais ils ne couvrent pas forcément ces prédicats calculés.

À confirmer avec `EXPLAIN QUERY PLAN` sur la base réelle avant d’ajouter des index : le bon choix peut être une colonne normalisée, un index SQLite adapté, une recherche préfixe, FTS5 ou une table de projection.

### P1 — rebuilds et concurrence côté Flutter

Une exécution du dashboard effectue plusieurs `setState` séparés : disponibilité, erreur, résumé et prédictions. Le timer lance ensuite une nouvelle exécution sans garde `requestInFlight`. Les réponses du résumé et de l’IA sont traitées dans des callbacks indépendants et peuvent arriver dans un ordre différent.

Les écrans principaux sont de très gros `StatefulWidget` (`main.dart` et `reservations_list_page.dart` dépassent chacun plusieurs milliers de lignes) et contiennent des listes, filtres et `SingleChildScrollView`. Cela ne prouve pas un frame drop sans profilage, mais augmente la zone reconstruite à chaque `setState`. Le problème doit être mesuré en mode profile avec Flutter DevTools, pas corrigé au jugé.

### P1 — chargement initial, bundle et cache web

Artefacts présents dans `hestia_app/build/web` :

- `main.dart.js` : environ 4,0 Mo non compressés ;
- dossier CanvasKit : environ 37 Mo ;
- logo applicatif : environ 188 Ko.

Le Dockerfile désactive la stratégie PWA (`flutter build web --pwa-strategy=none`). La configuration Nginx met `Cache-Control: no-store, no-cache...` sur la route SPA et sur tous les `.js`, `.css`, images, fonts, WASM et JSON (`docker/frontend/default.conf`). Ainsi, même si les fichiers sont versionnés/immutables dans le build, le navigateur ne peut pas les réutiliser entre sessions. Le cache des assets a probablement un impact supérieur à la compression du logo. Les symboles CanvasKit présents dans l’artefact doivent aussi être vérifiés pour ne pas être livrés inutilement en production.

### P2 — traitement synchrone et coûts mémoire

Plusieurs services parcourent des `CarbonPeriod` imbriquées sur réservations × nuits × chambres : disponibilité, capacité d’extras, index d’occupation et historique IA (`AvailabilityService.php:301-348`, `BookingService.php:673-776`, `YieldService.php:120-205`). Ces calculs sont synchrones dans la requête PHP et matérialisent de grandes collections avec `get()`.

Côté Flutter, les réponses complètes sont décodées et filtrées sur l’isolate UI ; l’écran d’édition charge aussi toute la liste pour sélectionner un ID. Les générations/aperçus PDF utilisent également des opérations potentiellement coûteuses dans le chemin interactif. Ce sont des candidats P2, à valider avec traces CPU et tailles de payloads.

### P2 — cache

Les endpoints principaux utilisent `Cache::remember`, souvent 30 à 120 secondes, avec un compteur de version global (`AvailabilityService.php:15-31`). Docker configure `CACHE_STORE=file`, alors que la configuration Laravel retombe par défaut sur `database`. Le cache file évite certaines requêtes mais reste un accès disque par requête ; le cache database ajoute une requête SQLite par lecture/écriture. La stratégie n’est pas intrinsèquement incorrecte, mais doit être mesurée avec hit rate, taille et contention. L’invalidation par version rend les anciennes clés inaccessibles sans les supprimer, ce qui peut laisser grossir le stockage.

### P3 — images et dépendances

Le logo de 188 Ko est le principal bitmap applicatif identifié, sans images de contenu lourdes. Il mérite une conversion WebP/PNG optimisée, mais ce n’est pas le premier levier. Les packages Flutter `pdf`, `printing`, `path_provider` et `share_plus` sont tous déclarés dans l’application ; l’effet bundle exact doit être isolé avec un build release et une analyse de taille. Ne pas retirer une dépendance uniquement sur son nom : mesurer d’abord.

## Ce qui n’est pas démontré

- Aucun chiffre de p50/p95, débit, taille JSON ou nombre de lignes n’a été inventé : la base de données de production n’est pas présente dans le dépôt et aucun serveur n’était lancé.
- Aucun problème de rendu précis (jank, nombre de frames, raster time) n’est confirmé sans profilage DevTools.
- Les index manquants ne doivent pas être déduits uniquement des migrations : il faut les plans SQLite sur les requêtes réelles et leurs cardinalités.

## Plan de mesure avant modification

1. Créer un jeu de données représentatif : réservations historiques, audits, paiements, chambres, organisations et séjours longs. Conserver un jeu petit et un jeu de charge.
2. Ajouter temporairement une instrumentation Laravel : durée totale, nombre de requêtes DB, temps DB cumulé, taille de réponse et endpoint. Activer `DB::listen` uniquement en environnement de profiling.
3. Mesurer séparément : ouverture dashboard, polling à 10 s, liste « all », liste d’une date, `active-reservations`, disponibilité, recherche client, prédictions IA et résumé de revenus.
4. Exécuter `EXPLAIN QUERY PLAN` sur les requêtes de dates, segments et recherches ; relever les scans complets et les tris temporaires.
5. En Flutter profile/web release, relever TTFB, téléchargement décompressé, parse JSON, premier frame utile, raster time et rebuilds avec DevTools. Comparer avec cache froid et cache chaud.
6. Construire une matrice de budgets : dashboard initial, rafraîchissement silencieux, liste paginée, recherche et édition d’une réservation. Les seuils exacts seront fixés à partir des usages métier et du matériel cible.

## Ordre recommandé des corrections

1. Séparer disponibilité/statuts et prédictions : supprimer le déclenchement IA du polling, ajouter un cache/dédoublonnage et un rafraîchissement explicite ou beaucoup plus lent pour l’IA.
2. Supprimer le N+1 de `visitCountForReservation()` par agrégation unique et réutilisation du résultat.
3. Introduire des endpoints ciblés et paginés : réservation par ID, liste avec projection minimale, audits/details à la demande.
4. Remplacer les requêtes par jour du résumé IA par une lecture de période et une agrégation unique.
5. Revoir les recherches et les dates après `EXPLAIN QUERY PLAN`, puis ajouter seulement les index/projections justifiés.
6. Réduire les rebuilds/concurrences Flutter avec un contrôleur de chargement, une requête groupée si elle est réellement bénéfique et des sous-widgets ciblés.
7. Activer la compression HTTP et un cache long pour assets fingerprintés ; conserver une politique courte pour `index.html`. Mesurer ensuite l’intérêt de CanvasKit/renderer et du poids des dépendances.
8. Optimiser les boucles de périodes et déplacer hors requête les traitements lourds qui ne sont pas nécessaires à la réponse interactive.

## Critères de validation

Une correction ne sera considérée comme validée qu’avec comparaison avant/après sur le même dataset : nombre de requêtes, temps DB, p50/p95 HTTP, taille de réponse, mémoire PHP, temps de parsing Flutter, nombre de rebuilds et temps de premier rendu. Toute modification d’index ou de cache devra aussi être vérifiée sur les écritures de réservation et l’invalidation, afin de ne pas échanger une latence de lecture contre une incohérence métier.
