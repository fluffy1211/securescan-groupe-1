#!/bin/sh
# =============================================================================
# SecureScan — Entrypoint DEV
#
# Rôle : préparer l'environnement avant de démarrer PHP-FPM en développement.
#
# Tâches :
#   1. Installer les dépendances Composer si vendor/ est absent
#   2. Créer les dossiers var/ requis par Symfony
#   3. Corriger les permissions (problème fréquent sur Windows/macOS)
#   4. Démarrer PHP-FPM (exec "$@")
#
# POINT D'ATTENTION — Windows WSL2 :
#   Les volumes montés depuis /mnt/c/ peuvent créer des fichiers appartenant
#   à root (uid=0). Ce script tourne en root pour pouvoir corriger ça.
#   Solution recommandée : stocker le projet dans ~/projects/ (filesystem WSL2).
#
# POINT D'ATTENTION — macOS :
#   Avec Docker Desktop et VirtioFS, les permissions sont généralement
#   correctes. Si des erreurs de permission apparaissent, activer
#   "Use Rosetta" dans Docker Desktop n'est PAS la solution ; vérifier
#   plutôt que le propriétaire du dossier hôte est bien votre utilisateur.
# =============================================================================

set -e

echo "==> [SecureScan DEV] Démarrage de l'entrypoint..."
echo "    APP_ENV=${APP_ENV:-dev}"
echo "    DATABASE_URL=${DATABASE_URL:-non définie}"

# ── 1. Dossiers Symfony var/ ──────────────────────────────────────────────────
# Créés ici plutôt que dans le Dockerfile car ils sont écrasés par le volume
# monté (le volume remplace le contenu du WORKDIR).
echo "==> Création des dossiers var/ ..."
mkdir -p var/cache var/log var/sessions

# ── 2. Permissions ────────────────────────────────────────────────────────────
# On utilise chmod 777 en développement uniquement pour contourner les
# problèmes de permissions cross-OS. Ne JAMAIS faire ça en production.
chmod -R 777 var/ 2>/dev/null || true

# Tenter de changer le propriétaire (peut échouer dans certains contextes rootless)
chown -R www-data:www-data var/ 2>/dev/null || true

# ── 3. Composer install ───────────────────────────────────────────────────────
# Déclenché automatiquement au premier démarrage (ou après un git clone).
# Le cache Composer est monté via volume (composer-cache:/root/.composer/cache).
if [ -f "composer.json" ] && [ ! -f "vendor/autoload.php" ]; then
    echo "==> vendor/ absent — installation des dépendances Composer..."
    composer install \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader
    echo "==> Composer install terminé."
elif [ ! -f "composer.json" ]; then
    echo "==> Aucun composer.json trouvé. Lancez 'make symfony-init' pour créer le projet."
else
    echo "==> vendor/ présent, skip composer install."
fi

# ── 4. Vérification SQLite ────────────────────────────────────────────────────
if [ ! -f "var/securescan.db" ]; then
    echo ""
    echo "  ┌─────────────────────────────────────────────────────────────┐"
    echo "  │  INFO : Base de données SQLite absente.                     │"
    echo "  │  Exécutez : make db-init                                    │"
    echo "  │  (php bin/console doctrine:schema:create)                   │"
    echo "  └─────────────────────────────────────────────────────────────┘"
    echo ""
fi

# ── 5. Vérification Semgrep ───────────────────────────────────────────────────
if command -v semgrep >/dev/null 2>&1; then
    SEMGREP_VER=$(semgrep --version 2>&1 | head -1)
    echo "==> Semgrep disponible : ${SEMGREP_VER}"
else
    echo "ERREUR : semgrep introuvable dans le PATH. Vérifiez le Dockerfile."
    exit 1
fi

# ── 6. PHP-FPM : désactiver clear_env ────────────────────────────────────────
# Par défaut PHP-FPM efface les variables d'environnement des workers (clear_env=yes).
# Semgrep a besoin de HOME, PATH et SEMGREP_* pour fonctionner depuis PHP-FPM.
# On s'assure que clear_env = no est actif à chaque démarrage du conteneur.
FPM_POOL_CONF="/usr/local/etc/php-fpm.d/www.conf"
if grep -q "^;clear_env = no" "${FPM_POOL_CONF}" 2>/dev/null; then
    sed -i 's/^;clear_env = no/clear_env = no/' "${FPM_POOL_CONF}"
    echo "==> PHP-FPM : clear_env = no activé."
elif ! grep -q "^clear_env" "${FPM_POOL_CONF}" 2>/dev/null; then
    echo "clear_env = no" >> "${FPM_POOL_CONF}"
    echo "==> PHP-FPM : clear_env = no ajouté."
else
    echo "==> PHP-FPM : clear_env déjà configuré."
fi

# ── 7. Répertoire cache Semgrep accessible par www-data ──────────────────────
mkdir -p /tmp/semgrep-cache
chmod 777 /tmp/semgrep-cache
echo "==> Semgrep cache : /tmp/semgrep-cache (777)"

# ── 8. SQLite WAL mode : évite les deadlocks en lecture/écriture concurrente ──
# Le mode WAL (Write-Ahead Logging) permet des lectures simultanées pendant les
# écritures, ce qui élimine l'erreur errno=35 "Resource deadlock avoided".
if [ -f "var/securescan.db" ]; then
    sqlite3 var/securescan.db "PRAGMA journal_mode=WAL; PRAGMA busy_timeout=30000;" > /dev/null 2>&1 || true
    echo "==> SQLite : mode WAL activé (busy_timeout=30s)"
fi

echo "==> Démarrage de PHP-FPM (DEV)..."
echo ""

# Exécuter la commande passée en argument (CMD : "php-fpm")
exec "$@"
