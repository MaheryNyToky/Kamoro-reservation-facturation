# Documentation API - Kamoro Hotel / HestiaPredict

## Objectif

Cette documentation décrit les deux backends du projet et formalise les contrats API à utiliser pour faire évoluer l'application sans casser Flutter, le dashboard Laravel ou le moteur IA.

Le projet est composé de deux services HTTP complémentaires :

| Service | Dossier | Rôle | Port local courant |
| --- | --- | --- | --- |
| Backend métier Laravel | `hestiapredict` | Source de vérité : chambres, réservations, utilisateurs, disponibilité, audit, orchestration IA | `http://127.0.0.1:8000` |
| Moteur IA FastAPI | `hestia-ai` | Service stateless de prévision d'occupation et de calcul des prix suggérés | `http://127.0.0.1:8001` |

Le frontend Flutter ne devrait appeler que Laravel. Laravel appelle FastAPI via `AI_ENGINE_URL` pour obtenir les prédictions. Si FastAPI est indisponible, Laravel repasse en mode fallback et applique les prix planchers.

## Fichiers OpenAPI

Les spécifications Swagger/OpenAPI versionnées sont dans :

| Service | Fichier | Usage |
| --- | --- | --- |
| Laravel | `docs/openapi/hestiapredict.openapi.yaml` | Importable dans Swagger Editor, Stoplight, Insomnia ou Postman |
| FastAPI | `docs/openapi/hestia-ai.openapi.yaml` | Référence statique du moteur IA |

FastAPI expose aussi automatiquement :

| URL | Description |
| --- | --- |
| `http://127.0.0.1:8001/docs` | Swagger UI généré par FastAPI |
| `http://127.0.0.1:8001/redoc` | ReDoc généré par FastAPI |
| `http://127.0.0.1:8001/openapi.json` | Spécification OpenAPI générée |

Pour Laravel, aucun package Swagger runtime n'est installé. Le fichier YAML statique sert donc de contrat officiel côté métier.

## Architecture Des Flux

### Disponibilité et réservation

1. Flutter demande les disponibilités à Laravel : `GET /api/live-availability` ou `GET /api/available-rooms`.
2. Flutter crée une réservation avec `POST /api/bookings`.
3. Laravel écrit la réservation et les chambres liées dans la base.
4. Laravel stocke le prix vendu dans `booking_room.price_snapshot_ariary`.
5. Les listes et audits lisent les prix snapshot pour garantir la traçabilité.

### Yield et IA

1. Flutter demande les prix au backend métier : `GET /api/dashboard/predictions`.
2. Laravel agrège :
   - historique des réservations actives,
   - prix planchers par catégorie,
   - capacité par catégorie.
3. Laravel appelle FastAPI : `POST /predict`.
4. FastAPI renvoie une prédiction par catégorie et par date.
5. Laravel réaligne les prix avec les contraintes métier :
   - une chambre à prix fixe reste au prix plancher,
   - un prix dynamique ne descend jamais sous le prix plancher,
   - le multiplicateur temps réel est plafonné.
6. Si FastAPI échoue, Laravel renvoie un résultat fallback avec `is_fallback: true`.

## Conventions Globales

### Format des dates

Toutes les dates métier sont en `YYYY-MM-DD`.

Exemples :

```json
"2026-07-01"
```

### Montants

Tous les prix sont des entiers en ariary.

Champs principaux :

| Champ | Sens |
| --- | --- |
| `base_price_ariary` | Prix plancher officiel d'une chambre |
| `fixed_price_ariary` | Prix fixe affichable, généralement égal au prix plancher |
| `adjusted_price_ariary` | Prix après yield côté Laravel |
| `suggested_price_ariary` | Prix recommandé final envoyé au frontend |
| `price_snapshot_ariary` | Prix réellement capturé au moment de la réservation |

### Statuts de réservation

| Valeur | Sens | Actif |
| --- | --- | --- |
| `en_attente` | Réservation en attente / prévisionnelle | Oui |
| `arrive` | Client arrivé / réservation confirmée. Peut être passé via `arrive_paid` ou `arrive_unpaid` pour définir le statut de paiement simultanément. | Oui |
| `annule` | Réservation annulée | Non |

Les calculs de disponibilité utilisent `en_attente` et `arrive`.

### Authentification

L'API Laravel expose `POST /api/login`, mais les autres routes ne sont actuellement pas protégées par token ou middleware d'authentification. Le login retourne uniquement l'utilisateur, pas de JWT ni Sanctum token.

Recommandation d'évolution :

1. Ajouter Sanctum ou Passport.
2. Protéger les routes sensibles : utilisateurs, création de réservation, update statut, audit.
3. Garder `/api/login` public.
4. Ajouter des permissions par rôle (`admin`, `receptionist`).

## Backend Laravel - API Métier

Base URL locale :

```text
http://127.0.0.1:8000/api
```

### `POST /login`

Authentifie un membre du staff.

Payload :

```json
{
  "email": "admin@kamoro.test",
  "password": "password"
}
```

Réponse `200` :

```json
{
  "status": "success",
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@kamoro.test",
    "role": "admin"
  }
}
```

Réponse `401` :

```json
{
  "status": "error",
  "message": "Identifiants incorrects"
}
```

### `GET /live-availability`

Retourne une synthèse de disponibilité par catégorie.

Query params :

| Nom | Requis | Description |
| --- | --- | --- |
| `date` | Non | Date ciblée. Défaut : date du jour Laravel |

Réponse :

```json
[
  {
    "type": "Chambre Double",
    "model": "Superieure",
    "base_price": 125000,
    "fixed_price": 125000,
    "is_fixed_price": false,
    "total": 8,
    "available": 5
  }
]
```

### `GET /available-rooms`

Retourne les chambres libres pour une période.

Query params :

| Nom | Requis | Validation |
| --- | --- | --- |
| `check_in` | Oui | `date` |
| `check_out` | Oui | `date`, strictement après `check_in` |

Réponse :

```json
[
  {
    "id": 12,
    "room_number": "103",
    "type": "Chambre Double",
    "model": "Superieure",
    "base_price_ariary": 125000,
    "fixed_price_ariary": 125000,
    "is_fixed_price": false
  }
]
```

### `POST /bookings`

Crée une réservation.

Payload minimal :

```json
{
  "client_name": "Jean Rakoto",
  "customer_phone": "0340000000",
  "customer_email": "jean@example.com",
  "check_in": "2026-07-01",
  "check_out": "2026-07-03",
  "room_ids": [12],
  "source": "Appel",
  "receptionist_name": "Admin"
}
```

Payload complet avec extras et prix dynamiques :

```json
{
  "client_name": "Jean Rakoto",
  "customer_phone": "0340000000",
  "customer_email": "jean@example.com",
  "check_in": "2026-07-01",
  "check_out": "2026-07-03",
  "room_ids": [12, 13],
  "extra_beds": 1,
  "extra_mattresses": 2,
  "room_prices": [
    { "id": 12, "price": 136000 },
    { "id": 13, "price": 136000 }
  ],
  "source": "Booking",
  "receptionist_name": "Admin"
}
```

Règles importantes :

| Cas | Prix retenu |
| --- | --- |
| Chambre `is_fixed_price=true` | Toujours `base_price_ariary` |
| Chambre dynamique avec `room_prices` | Prix fourni |
| Chambre dynamique sans `room_prices`, source `Booking` | `162500` |
| Chambre dynamique sans `room_prices`, autre source | `base_price_ariary` |

Réponse `201` :

```json
{
  "status": "success",
  "message": "Réservation enregistrée avec succès",
  "reference": "RES-A1B2C3"
}
```

### `POST /bookings/update-status`

Met à jour le statut d'une réservation.

Payload par id :

```json
{
  "id": 42,
  "status": "arrive"
}
```

Payload par référence :

```json
{
  "reference": "RES-A1B2C3",
  "status": "annule"
}
```

Réponses :

| Code | Cas |
| --- | --- |
| `200` | Statut mis à jour |
| `400` | Aucun `id` ni `reference` fourni |
| `404` | Réservation introuvable |
| `422` | Validation Laravel échouée |

### `GET /reservations/all`

Retourne les réservations non annulées, formatées pour le frontend.

Query params :

| Nom | Requis | Description |
| --- | --- | --- |
| `date` | Non | Si `all`, toutes les dates. Sinon filtre les réservations actives pendant cette date |

Réponse :

```json
[
  {
    "id": 42,
    "reference": "RES-A1B2C3",
    "client_name": "Jean Rakoto",
    "phone": "0340000000",
    "email": "jean@example.com",
    "check_in": "2026-07-01",
    "check_out": "2026-07-03",
    "status": "en_attente",
    "payment_status": "unbilled",
    "source": "Appel",
    "cancelled_by_name": null,
    "rooms": "2x Chambre Double (Superieure)",
    "room_numbers": "103, 104",
    "extra_beds": 0,
    "extra_mattresses": 0,
    "total_price": 272000,
    "fixed_total_price": 250000,
    "paid_amount_ariary": 0,
    "is_booking": false,
    "receptionist": "Admin",
    "latest_payment_processed_by": null,
    "created_at": "2026-06-15 10:00:00"
  }
]
```

### `GET /active-reservations`

Retourne les réservations présentes sur une date donnée, avec un format plus compact.

Query params :

| Nom | Requis | Description |
| --- | --- | --- |
| `date` | Non | Date ciblée. Défaut : date du jour |

### `GET /dashboard/predictions`

Retourne les prédictions de prix et d'occupation orchestrées par Laravel.

Query params :

| Nom | Requis | Défaut | Description |
| --- | --- | --- | --- |
| `days` | Non | `30` | Nombre de jours à retourner |
| `start_date` | Non | date du jour | Date de début |

Réponse IA disponible :

```json
{
  "status": "success",
  "mode": "ai",
  "ai_available": true,
  "is_fallback": false,
  "results": {
    "Chambre Double - Superieure": [
      {
        "date": "2026-07-01",
        "predicted_occupancy": 6,
        "fixed_price_ariary": 125000,
        "adjusted_price_ariary": 136000,
        "suggested_price_ariary": 136000,
        "base_price": 125000,
        "is_fixed_price": false
      }
    ]
  }
}
```

Réponse fallback :

```json
{
  "status": "success",
  "mode": "fallback",
  "ai_available": false,
  "is_fallback": true,
  "message": "Mode sécurité : IA indisponible, prix de base appliqués",
  "results": {}
}
```

### `GET /dashboard/audit-date`

Retourne les indicateurs financiers et d'occupation pour une date.

Query params :

| Nom | Requis | Description |
| --- | --- | --- |
| `date` | Non | Date auditée. Défaut : date du jour |

Réponse :

```json
{
  "status": "success",
  "rooms_confirmed": 12,
  "rooms_estimated": 18,
  "daily_ca_official": 1500000,
  "daily_ca_pending": 500000,
  "total_ca": 12000000,
  "period": "Depuis le début de l'année jusqu'au 01/07/2026"
}
```

### `GET /dashboard/ai-revenue-summary`

Retourne la simulation IA des revenus (CA prix fixe vs CA IA simulé) par journée.

Query params :

| Nom | Requis | Description |
| --- | --- | --- |
| `days` | Non | Nombre de jours (défaut : 30) |
| `start_date` | Non | Date de début (défaut : date du jour) |

Réponse :

```json
{
  "status": "success",
  "mode": "ai",
  "ai_available": true,
  "is_fallback": false,
  "rows": [
    {
      "date": "2026-07-01",
      "room_count": 5,
      "fixed_revenue_ariary": 625000,
      "ai_revenue_ariary": 680000,
      "delta_ariary": 55000
    }
  ],
  "totals": {
    "fixed_revenue_ariary": 625000,
    "ai_revenue_ariary": 680000,
    "delta_ariary": 55000
  }
}
```

### Endpoints PMS & Facturation

Ces endpoints gèrent le check-in, la facturation et les paiements.

| Méthode | Route | Description |
| --- | --- | --- |
| `GET` | `/clients/search` | Recherche un profil client par nom, prénom, téléphone ou pièce d'identité (auto-complétion). |
| `POST` | `/reservations/{id}/checkin` | Enregistre un client (guest) pour une réservation, incrémente sa fidélité, met à jour le statut à `arrive` et génère le folio. |
| `GET` | `/reservations/{id}/folio` | Récupère la facture (folio) associée à une réservation, avec le détail des lignes et des paiements. |
| `POST` | `/invoices/{id}/items` | Ajoute un élément à la facture (type: `room`, `extra`, `deposit`). |
| `POST` | `/invoices/{id}/payments` | Enregistre un paiement (méthodes: `Espèces`, `Carte Bancaire`, `Mobile Money`, etc.). |
| `POST` | `/invoices/{id}/generate-pdf` | Calcule les remises, génère le numéro de facture et produit le fichier PDF. |
| `GET` | `/invoices/{id}/pdf` | Télécharge le PDF de la facture. |
| `POST` | `/invoices/{id}/send-email` | Envoie la facture par email au client. |

#### `GET /clients/search`

Recherche un client pour l'auto-complétion. Query param : `query` (chaîne de recherche).

#### `POST /reservations/{id}/checkin`

Payload :
```json
{
  "first_name": "Jean",
  "last_name": "Rakoto",
  "full_name": "Jean Rakoto",
  "customer_phone": "0340000000",
  "phone_number": "0340000000",
  "date_of_birth": "1985-05-20",
  "id_type": "CIN",
  "id_number": "123456789012",
  "id_document_number": "123456789012",
  "loyalty_count": 5,
  "id_photo": "(file binary)"
}
```

#### `POST /invoices/{id}/generate-pdf`

Payload optionnel pour les remises :
```json
{
  "discount_mode": "percent",
  "discount_value": 10
}
```

### Endpoints utilisateurs

Ces endpoints gèrent le staff.

| Méthode | Route | Description |
| --- | --- | --- |
| `GET` | `/users` | Liste les utilisateurs triés par nom. |
| `POST` | `/users` | Crée un nouvel utilisateur (admin ou receptionist). |
| `POST` | `/users/update` | Met à jour les informations d'un utilisateur (id requis). |
| `DELETE` | `/users/{id}` | Supprime un utilisateur. |

## Application Flutter

### Optimisations et Rôles

- **Cache busting** : Les appels API incluent désormais un paramètre `_ts` (timestamp) pour éviter les problèmes de mise en cache des navigateurs.
- **Gestion des rôles** : 
    - Les administrateurs peuvent naviguer dans l'historique des réservations (jusqu'à 2 ans en arrière).
    - Les réceptionnistes sont limités aux réservations à partir de la date du jour.
- **Navigation** : Le passage du tableau de bord à la liste des réservations conserve désormais la date sélectionnée.

## Backend FastAPI - Moteur IA

Base URL locale :

```text
http://127.0.0.1:8001
```

### `GET /health`

Réponse :

```json
{
  "status": "ok"
}
```

### `POST /predict`

Calcule les prédictions à partir d'un historique agrégé.

Payload :

```json
{
  "base_prices": {
    "Chambre Double - Superieure": 125000
  },
  "days_to_predict": 30,
  "start_date": "2026-07-01",
  "history": [
    {
      "date": "2026-06-01",
      "room_type": "Chambre Double - Superieure",
      "rooms_booked": 4
    }
  ],
  "room_capacities": {
    "Chambre Double - Superieure": 8
  },
  "yield_strategy": [
    { "min_occupancy_rate": 80, "multiplier": 1.135 },
    { "min_occupancy_rate": 20, "multiplier": 1.045 },
    { "min_occupancy_rate": 0, "multiplier": 1.0 }
  ]
}
```

Réponse :

```json
{
  "status": "success",
  "results": {
    "Chambre Double - Superieure": [
      {
        "date": "2026-07-01",
        "predicted_occupancy": 6,
        "suggested_price_ariary": 136000,
        "base_price": 125000
      }
    ]
  }
}
```

Erreur métier :

| Code | Cas |
| --- | --- |
| `400` | Historique insuffisant pour entraîner Prophet |
| `422` | Payload invalide selon Pydantic |

## Règles De Prix

### FastAPI

Le moteur IA :

1. entraîne Prophet par catégorie de chambre,
2. applique les effets saisonniers internes :
   - mois cycloniques : demande réduite,
   - jours weekend : demande amplifiée,
3. plafonne `predicted_occupancy` à la capacité,
4. calcule le taux d'occupation,
5. sélectionne le premier multiplicateur dont `occupancy_rate >= min_occupancy_rate`,
6. calcule `suggested_price_ariary = round(base_price * multiplier, -3)`.

### Laravel

Laravel reste responsable des règles business finales :

1. les chambres fixes ne changent jamais de prix,
2. les prix dynamiques ne descendent jamais sous le prix plancher,
3. les catégories absentes de la réponse IA sont ajoutées avec prix plancher,
4. le fallback applique toujours les prix planchers.

## Stratégie D'Évolution

### Ajouter un endpoint Laravel

1. Ajouter la route dans `hestiapredict/routes/api.php`.
2. Ajouter la méthode dans `HotelManagementController` ou créer un contrôleur dédié si le domaine grandit.
3. Déplacer la logique métier dans un service.
4. Ajouter un test Feature.
5. Mettre à jour `docs/openapi/hestiapredict.openapi.yaml`.
6. Ajouter un exemple dans cette documentation si l'endpoint est public pour Flutter.

### Ajouter un endpoint FastAPI

1. Ajouter le modèle Pydantic dans `hestia-ai/app/models.py`.
2. Ajouter la route dans `hestia-ai/main.py` avec `summary`, `description` et `tags`.
3. Ajouter des tests unitaires sans entraîner Prophet si possible.
4. Vérifier `http://127.0.0.1:8001/openapi.json`.
5. Mettre à jour `docs/openapi/hestia-ai.openapi.yaml` si le contrat doit rester versionné.

### Versionner les contrats

Pour une future rupture de compatibilité :

| Type de changement | Recommandation |
| --- | --- |
| Ajout de champ optionnel | Garder la même version |
| Suppression ou renommage de champ | Créer `/api/v2` ou un nouveau schéma |
| Changement de sens d'un champ | Créer une migration de contrat et documenter |
| Changement de règle tarifaire | Ajouter un test de non-régression |

## Commandes Utiles

Lancer Laravel :

```bash
cd hestiapredict
php artisan serve --host=127.0.0.1 --port=8000
```

Lancer FastAPI :

```bash
cd hestia-ai
./venv/bin/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

Tester Laravel :

```bash
cd hestiapredict
php artisan test
```

Tester FastAPI :

```bash
cd hestia-ai
./venv/bin/python -m unittest discover
```

## Contrôles Qualité Recommandés

Avant de modifier les calculs de prix :

1. Exécuter `php artisan test`.
2. Exécuter `./venv/bin/python -m unittest discover`.
3. Vérifier `GET /api/dashboard/predictions?days=7`.
4. Vérifier qu'une chambre `is_fixed_price=true` conserve toujours son prix plancher.
5. Vérifier qu'un prix dynamique n'est jamais inférieur à `base_price_ariary`.
6. Vérifier le mode fallback en arrêtant FastAPI.

## Dette Technique Identifiée

| Sujet | Risque | Recommandation |
| --- | --- | --- |
| Routes Laravel non protégées | Accès non autorisé aux utilisateurs et réservations | Ajouter Sanctum et middlewares de rôle |
| Swagger Laravel statique | Risque d'écart entre code et spec | Ajouter un package OpenAPI ou générer la spec en CI |
| Scripts Python racine `test_*.py` | Bruit pendant `unittest discover` | Déplacer les scripts manuels hors pattern `test_*.py` |
| Contrat prix partagé entre Laravel et FastAPI | Risque de divergence | Maintenir des tests de contrat et exemples OpenAPI |
