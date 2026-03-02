#!/bin/sh
# =============================================================================
# SecureScan — Entrypoint PROD
#
# Rôle : effectuer les vérifications de sécurité et les migrations avant
#         de démarrer PHP-FPM en production.
#
# Tâches :
#   1. Vérifier que APP_SECRET est bien configuré
#   2. Créer les dossiers var/ (au cas où ils seraient absents)
#   3. Appliquer les migrations Doctrine si elles existent
#   4. Démarrer PHP-FPM
#
# Ce script tourne en USER www-data (défini dans le Dockerfile stage prod).
# =============================================================================

set -e

echo "==> [SecureScan PROD] Démarrage de l'entrypoint..."
echo "    APP_ENV=${APP_ENV}"

# ── 1. Vérification de sécurité : APP_SECRET ─────────────────────────────────
if [ -z "${APP_SECRET}" ] || \
   [ "${APP_SECRET}" = "changeme" ] || \
   [ "${APP_SECRET}" = "changeme_secret_32chars_minimum_dev" ]; then
    echo ""
    echo "  ╔══════════════════════════════════════════════════════════════╗"
    echo "  ║  ERREUR DE SÉCURITÉ : APP_SECRET non configuré !            ║"
    echo "  ║  Générez un secret sécurisé :                               ║"
    echo "  ║    php -r \"echo bin2hex(random_bytes(32));\"                 ║"
    echo "  ║  Et définissez-le dans votre .env.prod.local ou via         ║"
    echo "  ║  les variables d'environnement du serveur.                  ║"
    echo "  ╚══════════════════════════════════════════════════════════════╝"
    echo ""
    exit 1
fi

# ── 2. Dossiers var/ ──────────────────────────────────────────────────────────
echo "==> Vérification des dossiers var/ ..."
mkdir -p var/cache var/log var/sessions
chmod 775 var/ var/cache var/log var/sessions

# ── 3. Base de données SQLite ─────────────────────────────────────────────────
# Si le fichier SQLite n'existe pas, Doctrine le crée automatiquement
# lors de la première requête. On lance le schéma ici pour éviter
# une erreur au premier accès.
if [ ! -f "var/securescan.db" ]; then
    echo "==> Création du schéma SQLite initial..."
    php bin/console doctrine:schema:create --no-interaction --env=prod || true
fi

# ── 4. Migrations Doctrine (si disponibles) ───────────────────────────────────
# Les migrations sont optionnelles : si le dossier migrations/ est vide,
# la commande ne fait rien.
if [ -d "migrations" ] && [ "$(ls -A migrations 2>/dev/null)" ]; then
    echo "==> Application des migrations Doctrine..."
    php bin/console doctrine:migrations:migrate \
        --no-interaction \
        --allow-no-migration \
        --env=prod
fi

# ── 5. Vérification Semgrep ───────────────────────────────────────────────────
if ! command -v semgrep >/dev/null 2>&1; then
    echo "ERREUR CRITIQUE : semgrep introuvable. L'image est corrompue."
    exit 1
fi

echo "==> Démarrage de PHP-FPM (PROD)..."
exec "$@"
