# HestiaPredict - Kamoro Hotel

HestiaPredict est une application web de gestion hôtelière pour réception, réservations, check-in, folios, paiements et prévisions de prix.

Le projet est composé de trois parties :

| Partie | Technologie | Dossier | Rôle |
| --- | --- | --- | --- |
| Interface staff | Flutter Web | `hestia_app` | Écran de réception et gestion des opérations |
| Backend métier | Laravel | `hestiapredict` | API, base de données, règles métier |
| Moteur IA | FastAPI + Prophet | `hestia-ai` | Prévisions d’occupation et suggestions de prix |

## Ce que fait l'application

- gérer les réservations et les disponibilités des chambres ;
- faire les check-in avec auto-complétion client et photo d'identité ;
- gérer les folios, les extras et les paiements partiels ;
- consulter l'historique des réservations selon le rôle ;
- calculer des prix suggérés à partir de l'occupation passée.

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

Le frontend Flutter appelle Laravel. Laravel reste la source de vérité pour les chambres, réservations, utilisateurs et règles métier. FastAPI est un service stateless utilisé pour les prédictions.

Si FastAPI ne répond pas, Laravel bascule automatiquement sur des prix planchers. L'application reste donc utilisable sans le moteur IA.

## Structure Du Projet

```text
.
├── hestia_app/              # Application Flutter Web
├── hestiapredict/           # Backend Laravel
├── hestia-ai/               # Moteur IA FastAPI
├── docs/                    # Documentation API et OpenAPI
├── dev.sh                   # Lancement complet en mode développement
├── dev.ps1                  # Lancement Windows
├── start_project.sh         # Lancement simple avec build Flutter web existant
├── docker-compose.yml       # Base Docker expérimentale
└── database.sqlite          # Base SQLite locale utilisée par Laravel
```

## Prérequis

| Outil | Version recommandée |
| --- | --- |
| PHP | 8.3 ou supérieur |
| Composer | 2.x |
| Node.js | 20 ou supérieur |
| npm | 10 ou supérieur |
| Python | 3.11 ou supérieur |
| Flutter | SDK compatible Dart `^3.12.1` |
| SQLite | Inclus sur macOS/Linux dans la plupart des environnements |

Liens officiels d'installation :

| Outil | Lien |
| --- | --- |
| Git | https://git-scm.com/downloads |
| PHP | https://www.php.net/downloads |
| Composer | https://getcomposer.org/download/ |
| Node.js | https://nodejs.org/en/download |
| Python | https://www.python.org/downloads/ |
| Flutter | https://docs.flutter.dev/get-started/install |

Vérification rapide :

```bash
php -v
composer --version
node -v
npm -v
python3 --version
flutter --version
```

Sur Windows, installer ces outils puis redémarrer le terminal pour que tout soit disponible dans le `PATH`.
Si une commande manque, vérifier dans un terminal :

```powershell
php -v
composer --version
node -v
npm -v
python --version
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

Créer la base si elle n'existe pas :

```bash
cd ..
touch database.sqlite
cd hestiapredict
php artisan migrate
```

Injecter les données de démonstration de l'hôtel :

```bash
php artisan db:seed --class=KamoroHotelSeeder
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

## Premier Démarrage

1. Lancer le script adapté à votre système.
2. Attendre que les trois services soient prêts.
3. Ouvrir l'URL de l'application Flutter dans le navigateur.
4. Se connecter avec un compte de démonstration.
5. Tester une réservation, un check-in et le folio.

## Lancement Rapide

Depuis la racine du projet :

```bash
./dev.sh
```

Sous Windows :

```powershell
powershell -ExecutionPolicy Bypass -File .\dev.ps1
```

Si une commande n'est pas reconnue sur Windows, vérifier que `php`, `flutter`, `python` et `powershell` sont bien installés et disponibles dans le `PATH`.
Si `dev.ps1` est bloqué par la politique d'exécution, lancer PowerShell en administrateur puis exécuter :

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
```

Le script lance :

| Service | URL |
| --- | --- |
| Flutter Web | `http://127.0.0.1:8080/index.html` |
| Laravel Dashboard | `http://127.0.0.1:8000/dashboard` |
| Laravel API | `http://127.0.0.1:8000/api` |
| FastAPI Swagger | `http://127.0.0.1:8001/docs` |

Arrêter les services :

```bash
pkill -f 'uvicorn main:app|php artisan serve|php -S 127.0.0.1:8080|php -S localhost:8080'
```

## Test Local

Le parcours recommandé est l'application Flutter Web dans un navigateur.

- Sur macOS ou Linux, exécuter `./dev.sh` depuis la racine du projet.
- Sur Windows, exécuter `powershell -ExecutionPolicy Bypass -File .\dev.ps1` depuis la racine du projet.
- Les scripts créent automatiquement la base SQLite locale si elle est absente, puis lancent l'IA, Laravel et le frontend web.
- Ouvrir ensuite `http://127.0.0.1:8080/index.html` et se connecter avec les comptes de démonstration.

À vérifier :

- connexion avec `admin@kamorohotel.com` / `admin123` ou `reco1@kamorohotel.com` / `reco123` ;
- création et modification de réservation ;
- check-in d'une réservation ;
- consultation du folio et des paiements ;
- affichage des disponibilités et des suggestions de yield.

## Comptes De Démonstration

Après exécution du seeder `KamoroHotelSeeder`, les comptes suivants sont disponibles :

| Rôle | Email | Mot de passe |
| --- | --- | --- |
| Administrateur | `admin@kamorohotel.com` | `admin123` |
| Réceptionniste | `reco1@kamorohotel.com` | `reco123` |

Ces identifiants sont destinés à un environnement local ou de démonstration. Ne pas les utiliser en production.

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

Relancer le script global de développement ou le build et le serveur local :

```bash
pkill -f 'php -S 127.0.0.1:8080'
cd hestia_app
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000
cd build/web
php -S 127.0.0.1:8080
```

Dans Safari ou Chrome, faire un rafraîchissement complet ou vider le cache.

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
