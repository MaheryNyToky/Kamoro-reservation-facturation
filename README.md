# HestiaPredict - Kamoro Hotel

HestiaPredict est une application de gestion hôtelière conçue pour le Kamoro Hotel. Le projet centralise la disponibilité des chambres, la création des réservations, la gestion du personnel, le suivi des arrivées et un moteur de yield management basé sur des prévisions d'occupation.

L'application est composée de trois parties :

| Partie | Technologie | Dossier | Rôle |
| --- | --- | --- | --- |
| Application staff | Flutter Web | `hestia_app` | Interface réception et gestion des réservations |
| Backend métier | Laravel | `hestiapredict` | API principale, base de données, règles métier, fallback tarifaire |
| Moteur IA | FastAPI + Prophet | `hestia-ai` | Prévisions d'occupation et suggestions de prix |

## Fonctionnalités

- Authentification du personnel avec gestion des rôles (`admin`, `receptionist`).
- Tableau de bord réception avec simulation IA des gains de revenus (`ai-revenue-summary`).
- **Module PMS intégré** : 
    - **Auto-complétion et Fidélité client** : Recherche intelligente de clients existants (nom, téléphone, pièce d'identité) avec pré-remplissage automatique des formulaires de réservation et de check-in, et suivi du nombre de visites (compteur de fidélité incrémenté à chaque paiement).
    - **Check-in client** : Prise de photo d'identité (via `image_picker`), enregistrement des informations légales, auto-complétion des données des clients réguliers, et passage automatique du statut à `arrive`. Auparavant appelé "Arrivé", le bouton et le processus ont été renommés "Check-in" pour plus de clarté.
    - **Gestion des Folios** : Facturation détaillée par réservation avec ajout d'extras (lits, matelas, consommations) et de remises. Auparavant, la taxe de séjour s'affichait comme un item standard, elle est désormais extraite de la liste principale. L'accès au folio a également été sécurisé : il n'est plus accessible qu'après le check-in, sauf pour les administrateurs.
    - **Suivi des paiements** : Multi-modes (espèces, carte, mobile money) avec gestion des paiements partiels et soldes.
    - **Documents PDF** : Génération de factures professionnelles au format PDF (via `dompdf`) avec une mise en page compacte pensée pour tenir sur une page dans le cas standard, tout en restant lisible et partageable depuis Flutter.
- Gestion des extras (lits supplémentaires, matelas).
- Disponibilité en temps réel par catégorie de chambre avec **cache-busting** pour garantir la fraîcheur des données.
- Recherche et filtrage avancé des réservations.
- **Accès historique sécurisé** : Les administrateurs peuvent consulter l'historique complet, tandis que le staff est limité aux réservations futures et présentes.
- Création de réservations multi-chambres avec capture du prix au moment de la vente (`price_snapshot`).
- Gestion des statuts : `en_attente`, `arrive` (payé/partiel/non payé), `annule`.
- Moteur IA basé sur **Facebook Prophet** pour les prévisions d'occupation et suggestions de prix dynamiques.
- Documentation OpenAPI complète pour les deux backends.
- Tests automatisés Laravel (Feature/Unit), FastAPI et Flutter.

## Mises à jour récentes

- Les listes métier et compteurs liés à `en_attente` et à l'occupation confirmée ont été resserrés pour réduire les valeurs affichées en retard.
- La saisie d'acompte est plus robuste côté réception: validation plus explicite, maintien du formulaire pendant l'envoi et protection contre les doubles soumissions.
- La facture PDF a été compactée pour tenir sur une seule page dans le cas standard, sans tomber dans une police trop petite.

## Architecture

```text
Flutter Web
    |
    | HTTP
    v
Laravel API
    |
    | HTTP interne via AI_ENGINE_URL
    v
FastAPI AI Engine
```

Le frontend Flutter appelle uniquement Laravel. Laravel reste la source de vérité pour les chambres, réservations, utilisateurs et règles métier. FastAPI est un service stateless appelé par Laravel pour produire des prédictions.

Si FastAPI ne répond pas, Laravel renvoie automatiquement des prix planchers via le mode fallback. Cela permet à l'application de rester utilisable même sans IA.

## Structure Du Projet

```text
.
├── hestia_app/              # Application Flutter Web
├── hestiapredict/           # Backend Laravel
├── hestia-ai/               # Moteur IA FastAPI
├── docs/                    # Documentation API et OpenAPI
├── dev.sh                   # Lancement complet en mode développement
├── start_project.sh         # Lancement simple avec build Flutter web existant
├── docker-compose.yml       # Base Docker expérimentale
└── database.sqlite          # Base SQLite locale créée sur chaque machine
```

## Prérequis

Installer les outils suivants :

| Outil | Version recommandée |
| --- | --- |
| PHP | 8.4 ou supérieur |
| Composer | 2.x |
| Node.js | 20 ou supérieur |
| npm | 10 ou supérieur |
| Python | 3.11 ou supérieur |
| Flutter | SDK compatible Dart `^3.12.0` |
| SQLite | Inclus sur macOS/Linux dans la plupart des environnements |

Vérification rapide :

```bash
php -v
composer --version
node -v
npm -v
python3 --version
flutter --version
```

## Installation

### 1. Cloner le projet

```bash
git clone <url-du-repository>
cd <nom-du-repository>
```

### 2. Installer Laravel

```bash
cd hestiapredict
composer install
cp .env.example .env
php artisan key:generate
```

Par défaut, le projet utilise SQLite :

```env
DB_CONNECTION=sqlite
DB_DATABASE=../database.sqlite
AI_ENGINE_URL=http://127.0.0.1:8001
```

La base SQLite n'est pas versionnée dans Git. Chaque machine doit créer sa propre copie locale si elle n'existe pas :

```bash
cd ..
touch database.sqlite
cd hestiapredict
php artisan migrate
php artisan db:seed
```

Comptes de connexion de base après génération locale :

- Superadmin : `superadmin@kamorohotel.com` / `super181802`
- Admin : `admin@kamorohotel.com` / `admin123`
- Réception : `reco1@kamorohotel.com` / `reco123`

Le seed standard recrée seulement les comptes, les chambres et les tarifs. Si tu veux aussi le gros jeu de données de démonstration avec les clients et les réservations de test, lance :

```bash
php artisan db:seed --class=ClientTestDatasetSeeder
```

Installer les dépendances frontend Laravel :

```bash
npm install
```

### 3. Installer le moteur IA FastAPI

```bash
cd ../hestia-ai
python3 -m venv venv
./venv/bin/python -m pip install --upgrade pip
./venv/bin/python -m pip install fastapi uvicorn pandas prophet
```

### 4. Installer Flutter

```bash
cd ../hestia_app
flutter pub get
```

## Lancement Rapide

Depuis la racine du projet :

```bash
./dev.sh
```

Le script lance :

| Service | URL |
| --- | --- |
| Flutter Web | `http://127.0.0.1:8080/index.html` |
| Laravel Dashboard | `http://127.0.0.1:8000/dashboard` |
| Laravel API | `http://127.0.0.1:8000/api` |
| FastAPI Swagger | `http://127.0.0.1:8001/docs` |

## Auto-Demarrage Windows

Pour lancer Kamoro automatiquement a l'ouverture de session Windows :

```powershell
.\Activer-AutoDemarrage-Kamoro.ps1
```

Le script cree un raccourci dans le dossier `Startup` de Windows. Il lance l'environnement Docker en arriere-plan et attend que Docker soit pret.
Avant de demarrer, il recupere automatiquement les derniers changements du depot avec `git pull --ff-only origin main`.

Pour desactiver ce comportement :

```powershell
.\Desactiver-AutoDemarrage-Kamoro.ps1
```

Arrêter les services :

```bash
pkill -f 'uvicorn main:app|php artisan serve|php -S 127.0.0.1:8080|php -S localhost:8080'
```

## Test

Le parcours de test recommandé est l'application Flutter Web dans un navigateur.

- Sur macOS ou Linux, exécuter `./dev.sh` depuis la racine du projet.
- Sur Windows, utiliser `WSL` ou `Git Bash` pour lancer le script Bash, ou démarrer les services manuellement avec les commandes plus bas.
- `dev.sh` crée automatiquement la base SQLite locale si elle est absente, puis lance l'IA, Laravel et le frontend web.
- Ouvrir ensuite `http://127.0.0.1:8080/index.html` et se connecter avec les comptes de démonstration.

Ce vous devez vérifier :

- connexion avec `admin@kamorohotel.com` / `admin123` ou `reco1@kamorohotel.com` / `reco123` ;
- création et modification de réservation ;
- check-in d'une réservation ;
- consultation du folio et des paiements ;
- affichage des disponibilités et des suggestions de yield.

## Lancement Manuel

### FastAPI

```bash
cd hestia-ai
./venv/bin/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

### Laravel

```bash
cd hestiapredict
AI_ENGINE_URL=http://127.0.0.1:8001 php artisan serve --host=127.0.0.1 --port=8000
```

### Flutter Web

Le script `dev.sh` construit désormais l'application Flutter et la sert via PHP pour une meilleure stabilité.
Pour le faire manuellement :

```bash
cd hestia_app
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000
cd build/web
php -S 127.0.0.1:8080
```

Pour pointer Flutter vers une autre API lors du build :

```bash
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://votre-api.com
```

## Comptes De Démonstration

Après exécution du seeder `KamoroHotelSeeder`, les comptes suivants sont disponibles :

| Rôle | Email | Mot de passe |
| --- | --- | --- |
| Administrateur | `admin@kamorohotel.com` | `admin123` |
| Réceptionniste | `reco1@kamorohotel.com` | `reco123` |

Ces identifiants sont destinés à un environnement local ou de démonstration. Ne pas les utiliser en production.

## Variables D'Environnement

### Laravel

Fichier : `hestiapredict/.env`

| Variable | Description | Exemple |
| --- | --- | --- |
| `APP_ENV` | Environnement Laravel | `local` |
| `APP_DEBUG` | Affichage des erreurs détaillées | `true` |
| `APP_KEY` | Clé applicative Laravel | générée par `php artisan key:generate` |
| `DB_CONNECTION` | Driver de base de données | `sqlite` |
| `DB_DATABASE` | Chemin de la base SQLite | `../database.sqlite` |
| `AI_ENGINE_URL` | URL du moteur FastAPI | `http://127.0.0.1:8001` |
| `CORS_ALLOWED_ORIGINS` | Origines autorisées pour Flutter | `http://localhost:8080,http://127.0.0.1:8080` |

### Flutter

La base URL de l'API est définie dans `hestia_app/lib/core/app_config.dart`.

Valeur par défaut :

```text
http://localhost:8000
```

Override à l'exécution :

```bash
--dart-define=API_BASE_URL=http://localhost:8000
```

Le mode démo admin est désactivé par défaut. Il ne doit être activé que localement :

```bash
--dart-define=ENABLE_DEMO_MODE=true
```

## Documentation API

Documentation globale :

```text
docs/API_DOCUMENTATION.md
```

Spécifications OpenAPI :

```text
docs/openapi/hestiapredict.openapi.yaml
docs/openapi/hestia-ai.openapi.yaml
```

FastAPI expose aussi sa documentation interactive :

```text
http://localhost:8001/docs
http://localhost:8001/redoc
http://localhost:8001/openapi.json
```

## Tests

### Laravel

```bash
cd hestiapredict
php artisan test
```

### FastAPI

```bash
cd hestia-ai
./venv/bin/python -m unittest discover -s tests
```

### Flutter

```bash
cd hestia_app
dart analyze
flutter test
```

## Qualité Et Sécurité

Le projet applique plusieurs garde-fous :

- validation stricte des entrées Laravel ;
- mots de passe hashés via le cast Laravel `hashed` ;
- CORS configurable via variable d'environnement ;
- moteur IA bindé localement en développement ;
- mode fallback lorsque FastAPI est indisponible ;
- prix fixes non modifiables par le yield ;
- prix dynamiques jamais inférieurs au prix plancher ;
- tests de non-régression sur les calculs de prix.

Point à prévoir pour une mise en production :

- ajouter une authentification API complète avec Sanctum ou Passport ;
- protéger les routes sensibles par rôle ;
- remplacer les identifiants de démonstration ;
- configurer HTTPS ;
- déplacer la base SQLite vers PostgreSQL ou MySQL ;
- ajouter une CI pour exécuter les tests et valider les specs OpenAPI.

## Build Production

### Flutter Web

```bash
cd hestia_app
flutter build web --dart-define=API_BASE_URL=https://api.example.com
```

Le build est généré dans :

```text
hestia_app/build/web
```

### Laravel

```bash
cd hestiapredict
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan migrate --force
```

### FastAPI

En production, lancer FastAPI derrière un reverse proxy :

```bash
cd hestia-ai
./venv/bin/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

## Dépannage

### Flutter ne reflète pas les modifications

Relancer simplement le script global de développement ou relancer manuellement le build et le serveur local :

```bash
pkill -f 'php -S 127.0.0.1:8080'
cd hestia_app
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000
cd build/web
php -S 127.0.0.1:8080
```

Dans Safari ou Chrome, faire un rafraîchissement complet ou vider le cache (la PWA est désactivée en dev pour éviter la persistance).

### FastAPI ne démarre pas

Vérifier l'environnement Python :

```bash
cd hestia-ai
./venv/bin/python -c "import fastapi, uvicorn, pandas, prophet"
```

Si l'import échoue :

```bash
./venv/bin/python -m pip install fastapi uvicorn pandas prophet
```

### Laravel ne trouve pas la base SQLite

Depuis la racine :

```bash
touch database.sqlite
cd hestiapredict
php artisan migrate
```

### Les prédictions passent en fallback

Vérifier que FastAPI répond :

```bash
curl http://127.0.0.1:8001/health
```

Vérifier que Laravel pointe vers le bon moteur :

```bash
grep AI_ENGINE_URL hestiapredict/.env
```

## Licence

Projet académique développé pour la gestion hôtelière et le yield management du Kamoro Hotel. Adapter la licence avant toute distribution publique.
