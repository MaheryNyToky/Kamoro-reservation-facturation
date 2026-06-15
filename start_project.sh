#!/bin/bash

# Script de démarrage pour le projet HestiaPredict.
# Il lance Laravel, le moteur IA FastAPI et l'app Flutter Web sur les ports configurés.

# Récupération du chemin absolu du projet pour éviter les erreurs de dossier courant.
PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
LOG_DIR="$PROJECT_ROOT/.dev-logs"

mkdir -p "$LOG_DIR/matplotlib" "$LOG_DIR/cache"
touch "$PROJECT_ROOT/database.sqlite"

find_ai_python() {
    for candidate in "$PROJECT_ROOT/hestia-ai/venv/bin/python" "$PROJECT_ROOT/hestia-ai/.venv/bin/python" python3; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c "import fastapi, uvicorn" >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

echo "Démarrage des services HestiaPredict..."

# 1. Démarrage du moteur IA FastAPI sur l'interface locale uniquement.
echo "Lancement de l'IA (port 8001)..."
cd "$PROJECT_ROOT/hestia-ai"
AI_PYTHON="$(find_ai_python)" || {
    echo "[ERREUR] Aucun environnement Python valide pour l'IA."
    echo "   Créez/réparez l'environnement avec : cd hestia-ai && python3 -m venv venv && ./venv/bin/python -m pip install fastapi uvicorn pandas"
    exit 1
}
nohup env MPLCONFIGDIR="$LOG_DIR/matplotlib" XDG_CACHE_HOME="$LOG_DIR/cache" \
    "$AI_PYTHON" -m uvicorn main:app --host 127.0.0.1 --port 8001 > /dev/null 2>&1 &
AI_PID=$!
disown "$AI_PID" 2>/dev/null || true

# 2. Démarrage du backend Laravel.
echo "Lancement du backend Laravel (port 8000)..."
cd "$PROJECT_ROOT/hestiapredict"
nohup env AI_ENGINE_URL="http://127.0.0.1:8001" php artisan serve --port=8000 > /dev/null 2>&1 &
LARAVEL_PID=$!
disown "$LARAVEL_PID" 2>/dev/null || true

# 3. Démarrage de l'application Flutter Web.
echo "Lancement de l'app Flutter (port 8080)..."
cd "$PROJECT_ROOT/hestia_app"
if [ -d "build/web" ]; then
    nohup python3 -m http.server 8080 --directory build/web > /dev/null 2>&1 &
    FLUTTER_PID=$!
    disown "$FLUTTER_PID" 2>/dev/null || true
else
    echo "[ATTENTION] Build web Flutter non trouvé. L'app sur 8080 risque de ne pas répondre."
fi

echo ""
echo "[OK] Tous les services ont été lancés en arrière-plan."
echo "Vous pouvez maintenant ouvrir Safari sur :"
echo "   - Dashboard Laravel : http://localhost:8000"
echo "   - Application Flutter : http://localhost:8080"
echo ""
echo "Pour arrêter les services : pkill -f 'artisan|uvicorn|http.server'"
