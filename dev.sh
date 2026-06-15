#!/usr/bin/env bash

# Script de développement pour HestiaPredict avec rechargement côté navigateur.
set -e

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
LOG_DIR="$PROJECT_ROOT/.dev-logs"

mkdir -p "$LOG_DIR"
mkdir -p "$LOG_DIR/matplotlib" "$LOG_DIR/cache"

find_ai_python() {
    for candidate in "$PROJECT_ROOT/hestia-ai/venv/bin/python" "$PROJECT_ROOT/hestia-ai/.venv/bin/python" python3; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c "import fastapi, uvicorn" >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done

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

echo "Lancement de l'environnement de développement..."

echo "Arrêt des anciens services éventuels..."
pkill -f "uvicorn main:app" 2>/dev/null || true
pkill -f "php artisan serve" 2>/dev/null || true
pkill -f "flutter_tools.*web-server" 2>/dev/null || true
pkill -f "flutter run -d web-server" 2>/dev/null || true
pkill -f "php -S localhost:8080" 2>/dev/null || true
pkill -f "php -S 127.0.0.1:8080" 2>/dev/null || true

# 1. Moteur IA
echo "[AI] Lancement sur le port 8001..."
cd "$PROJECT_ROOT/hestia-ai"
AI_PYTHON="$(find_ai_python)" || {
    echo "[ERREUR] Aucun environnement Python valide pour l'IA."
    echo "   Créez/réparez l'environnement avec : cd hestia-ai && python3 -m venv venv && ./venv/bin/python -m pip install fastapi uvicorn pandas"
    exit 1
}
nohup env MPLCONFIGDIR="$LOG_DIR/matplotlib" XDG_CACHE_HOME="$LOG_DIR/cache" WATCHFILES_FORCE_POLLING=true \
    "$AI_PYTHON" -m uvicorn main:app --host 127.0.0.1 --port 8001 > "$LOG_DIR/ai.log" 2>&1 &
AI_PID=$!
wait_for_url "AI" "http://127.0.0.1:8001/health" "$LOG_DIR/ai.log" 30

# 2. Backend Laravel
echo "[Laravel] Lancement sur le port 8000..."
cd "$PROJECT_ROOT/hestiapredict"
touch "$PROJECT_ROOT/database.sqlite"
echo "[Laravel] Migration de la base..."
php artisan migrate --force > "$LOG_DIR/migrate.log" 2>&1
nohup env AI_ENGINE_URL="http://127.0.0.1:8001" php artisan serve --host=127.0.0.1 --port=8000 > "$LOG_DIR/laravel.log" 2>&1 &
LARAVEL_PID=$!
wait_for_url "Laravel" "http://127.0.0.1:8000/api/live-availability" "$LOG_DIR/laravel.log" 30

# 3. Build Flutter Web puis serveur statique stable.
# On désactive le mode PWA pour éviter qu'un ancien service worker
# garde une version obsolète de l'application dans le navigateur.
echo "[Flutter] Build web..."
cd "$PROJECT_ROOT/hestia_app"
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000 > "$LOG_DIR/flutter.log" 2>&1

echo "[Flutter] Lancement sur le port 8080 (prêt pour Safari)..."
cd "$PROJECT_ROOT/hestia_app/build/web"
nohup php -S 127.0.0.1:8080 >> "$LOG_DIR/flutter.log" 2>&1 &
FLUTTER_PID=$!
wait_for_url "Flutter" "http://127.0.0.1:8080/index.html" "$LOG_DIR/flutter.log" 90

echo ""
echo "[OK] Environnement prêt."
echo "Liens à ouvrir dans Safari :"
echo "   - App Flutter : http://127.0.0.1:8080/index.html"
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
echo "Relancez simplement ./dev.sh pour arrêter les anciennes instances et reconstruire."
