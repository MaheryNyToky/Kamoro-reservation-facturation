#!/usr/bin/env bash

# Script de développement pour HestiaPredict avec rechargement côté navigateur.
set -e

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
LOG_DIR="$PROJECT_ROOT/.dev-logs"
AI_REQUIREMENTS="$PROJECT_ROOT/hestia-ai/requirements.txt"
AI_BOOTSTRAP_VENV="$PROJECT_ROOT/hestia-ai/.venv"

mkdir -p "$LOG_DIR"
mkdir -p "$LOG_DIR/matplotlib" "$LOG_DIR/cache"

python_has_ai_deps() {
    local python_bin="$1"
    "$python_bin" -c "import fastapi, uvicorn, pandas, prophet" >/dev/null 2>&1
}

bootstrap_ai_python() {
    local bootstrap_python="$1"

    echo "[AI] Création de l'environnement Python dans $AI_BOOTSTRAP_VENV..." >&2
    if ! "$bootstrap_python" -m venv "$AI_BOOTSTRAP_VENV" > "$LOG_DIR/ai-bootstrap.log" 2>&1; then
        echo "[ERREUR] Impossible de créer le venv Python pour l'IA." >&2
        echo "[LOG] Dernières lignes du log : $LOG_DIR/ai-bootstrap.log" >&2
        tail -n 40 "$LOG_DIR/ai-bootstrap.log" 2>/dev/null || true
        return 1
    fi

    echo "[AI] Installation des dépendances Python..." >&2
    if ! "$AI_BOOTSTRAP_VENV/bin/python" -m pip install -r "$AI_REQUIREMENTS" > "$LOG_DIR/ai-install.log" 2>&1; then
        echo "[ERREUR] Installation des dépendances IA impossible." >&2
        echo "[LOG] Dernières lignes du log : $LOG_DIR/ai-install.log" >&2
        tail -n 40 "$LOG_DIR/ai-install.log" 2>/dev/null || true
        return 1
    fi

    if python_has_ai_deps "$AI_BOOTSTRAP_VENV/bin/python"; then
        echo "$AI_BOOTSTRAP_VENV/bin/python"
        return 0
    fi

    echo "[ERREUR] Le venv IA a bien été créé mais les modules attendus restent indisponibles." >&2
    return 1
}

find_ai_python() {
    local candidate

    for candidate in "$PROJECT_ROOT/hestia-ai/venv/bin/python" "$PROJECT_ROOT/hestia-ai/.venv/bin/python" "$(command -v python3 2>/dev/null)"; do
        if [ -n "$candidate" ] && [ -x "$candidate" ] && python_has_ai_deps "$candidate"; then
            echo "$candidate"
            return 0
        fi
    done

    if command -v python3 >/dev/null 2>&1; then
        bootstrap_ai_python "$(command -v python3)"
        return $?
    fi

    return 1
}

wait_for_url() {
    local name="$1"
    local url="$2"
    local log_file="$3"
    local attempts="${4:-30}"

    for ((i = 1; i <= attempts; i++)); do
        if curl -fsS "$url" >/dev/null 2>&1; then
            echo "[OK] [$name] Service prêt."
            return 0
        fi
        sleep 1
    done

    echo "[ERREUR] [$name] Service indisponible après ${attempts}s."
    echo "[LOG] Dernières lignes du log : $log_file"
    tail -n 40 "$log_file" 2>/dev/null || true
    return 1
}

ensure_laravel_dependencies() {
    if [ -f "$PROJECT_ROOT/hestiapredict/vendor/autoload.php" ]; then
        return 0
    fi

    echo "[Laravel] Dépendances Composer absentes, installation..."
    if ! composer install --no-interaction --prefer-dist > "$LOG_DIR/composer.log" 2>&1; then
        echo "[ERREUR] Installation des dépendances Laravel impossible."
        echo "[LOG] Dernières lignes du log : $LOG_DIR/composer.log"
        tail -n 40 "$LOG_DIR/composer.log" 2>/dev/null || true
        return 1
    fi

    if [ -f "$PROJECT_ROOT/hestiapredict/vendor/autoload.php" ]; then
        return 0
    fi

    echo "[ERREUR] Composer s'est terminé sans générer vendor/autoload.php."
    return 1
}

echo "Lancement de l'environnement de développement..."

cleanup() {
    pkill -f "uvicorn main:app" 2>/dev/null || true
    pkill -f "php artisan serve" 2>/dev/null || true
    pkill -f "flutter_tools.*web-server" 2>/dev/null || true
    pkill -f "flutter run -d web-server" 2>/dev/null || true
    pkill -f "php -S localhost:8080" 2>/dev/null || true
    pkill -f "php -S 127.0.0.1:8080" 2>/dev/null || true
    pkill -f "php -S 0.0.0.0:8080" 2>/dev/null || true
    pkill -f "php artisan serve --host=0.0.0.0 --port=8000" 2>/dev/null || true
    pkill -f "php artisan serve --host=127.0.0.1 --port=8000" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

echo "Arrêt des anciens services éventuels..."
cleanup

# 1. Moteur IA
echo "[AI] Lancement sur le port 8001..."
cd "$PROJECT_ROOT/hestia-ai"
AI_PYTHON="$(find_ai_python)" || {
    echo "[ERREUR] Aucun environnement Python valide pour l'IA."
    echo "   Créez/réparez l'environnement avec : cd hestia-ai && python3 -m venv .venv && ./.venv/bin/python -m pip install -r requirements.txt"
    exit 1
}
nohup env MPLCONFIGDIR="$LOG_DIR/matplotlib" XDG_CACHE_HOME="$LOG_DIR/cache" WATCHFILES_FORCE_POLLING=true \
    "$AI_PYTHON" -m uvicorn main:app --host 0.0.0.0 --port 8001 > "$LOG_DIR/ai.log" 2>&1 &
AI_PID=$!
wait_for_url "AI" "http://127.0.0.1:8001/health" "$LOG_DIR/ai.log" 30

# 2. Backend Laravel
echo "[Laravel] Lancement sur le port 8000..."
cd "$PROJECT_ROOT/hestiapredict"
touch "$PROJECT_ROOT/hestiapredict/database.sqlite"
php scripts/sync_sqlite_database.php
if ! ensure_laravel_dependencies; then
    exit 1
fi
php artisan optimize:clear > "$LOG_DIR/optimize-clear.log" 2>&1 || true
echo "[Laravel] Migration de la base..."
if ! env DB_DATABASE="$PROJECT_ROOT/hestiapredict/database.sqlite" CACHE_STORE=file php artisan migrate --force > "$LOG_DIR/migrate.log" 2>&1; then
    echo "[ERREUR] Migration Laravel impossible."
    echo "[LOG] Dernières lignes du log : $LOG_DIR/migrate.log"
    tail -n 40 "$LOG_DIR/migrate.log" 2>/dev/null || true
    exit 1
fi
echo "[Laravel] Création des comptes et données de base..."
if ! env DB_DATABASE="$PROJECT_ROOT/hestiapredict/database.sqlite" CACHE_STORE=file php artisan db:seed --force --class=Database\\Seeders\\KamoroHotelSeeder > "$LOG_DIR/seed.log" 2>&1; then
    echo "[ERREUR] Seed Laravel impossible."
    echo "[LOG] Dernières lignes du log : $LOG_DIR/seed.log"
    tail -n 40 "$LOG_DIR/seed.log" 2>/dev/null || true
    exit 1
fi
nohup env AI_ENGINE_URL="http://127.0.0.1:8001" DB_DATABASE="$PROJECT_ROOT/hestiapredict/database.sqlite" CACHE_STORE=file SESSION_DRIVER=file php artisan serve --host=0.0.0.0 --port=8000 > "$LOG_DIR/laravel.log" 2>&1 &
LARAVEL_PID=$!
wait_for_url "Laravel" "http://127.0.0.1:8000/api/health" "$LOG_DIR/laravel.log" 30

# 3. Build Flutter Web puis serveur statique stable.
# On désactive le mode PWA pour éviter qu'un ancien service worker
# garde une version obsolète de l'application dans le navigateur.
echo "[Flutter] Build web..."
cd "$PROJECT_ROOT/hestia_app"
if ! flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000 > "$LOG_DIR/flutter.log" 2>&1; then
    echo "[ERREUR] Build Flutter impossible."
    echo "[LOG] Dernières lignes du log : $LOG_DIR/flutter.log"
    tail -n 40 "$LOG_DIR/flutter.log" 2>/dev/null || true
    exit 1
fi

echo "[Flutter] Lancement sur le port 8080 (prêt pour Safari)..."
cd "$PROJECT_ROOT/hestia_app/build/web"
nohup php -S 0.0.0.0:8080 >> "$LOG_DIR/flutter.log" 2>&1 &
FLUTTER_PID=$!
wait_for_url "Flutter" "http://localhost:8080/index.html" "$LOG_DIR/flutter.log" 90

echo ""
echo "[OK] Environnement prêt."
echo "Liens à ouvrir dans Safari :"
echo "   - App Flutter : http://localhost:8080/index.html"
echo "   - Dashboard Laravel : http://127.0.0.1:8000/dashboard"
echo "   - API IA : http://127.0.0.1:8001/docs"
echo ""
echo "Logs :"
echo "   - $LOG_DIR/ai.log"
echo "   - $LOG_DIR/migrate.log"
echo "   - $LOG_DIR/laravel.log"
echo "   - $LOG_DIR/flutter.log"
echo ""
echo "PIDs :"
echo "   - AI : $AI_PID"
echo "   - Laravel : $LARAVEL_PID"
echo "   - Flutter : $FLUTTER_PID"
echo ""
echo "Gardez ce terminal ouvert. Utilisez Ctrl+C pour arrêter les services."

wait
