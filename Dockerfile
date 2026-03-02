# =============================================================================
# SecureScan — Dockerfile multi-stage
#
# Base    : PHP 8.2-FPM (Debian Bookworm) — choisi plutôt qu'Alpine pour
#           la compatibilité maximale avec les packages Python / Semgrep.
#
# Stages  :
#   base  → dépendances communes (PHP extensions, Semgrep, Composer, Git)
#   dev   → + Xdebug, entrypoint qui installe Composer au démarrage
#   prod  → COPY du code, composer --no-dev, cache warmup, OPcache durci
#
# POINT D'ATTENTION — compatibilité OS :
#   • Windows (WSL2) : BuildKit doit être activé (DOCKER_BUILDKIT=1).
#     Les volumes montés peuvent créer des fichiers appartenant à root ;
#     l'entrypoint-dev.sh corrige les permissions au démarrage.
#   • macOS (Apple Silicon) : l'image est multi-arch (amd64/arm64).
#     Semgrep a des wheels natifs arm64 depuis la v1.50.
#   • Linux : aucune particularité.
# =============================================================================

# =============================================================================
# Stage BASE — dépendances partagées dev + prod
# =============================================================================
FROM php:8.4-fpm AS base

LABEL org.opencontainers.image.title="SecureScan" \
      org.opencontainers.image.description="PHP 8.2 + Semgrep security scanner" \
      org.opencontainers.image.source="https://github.com/your-org/securescan"

# ── Packages système ──────────────────────────────────────────────────────────
# git            : git clone des repos publics analysés (besoin réseau sortant)
# unzip          : requis par Composer pour extraire les archives .zip
# curl           : utilitaire réseau, HealthCheck
# libicu-dev     : bibliothèque ICU → extension PHP intl (i18n Symfony)
# libzip-dev     : bibliothèque zlib → extension PHP zip
# libsqlite3-dev : entêtes SQLite → extension PHP pdo_sqlite
# sqlite3        : client CLI SQLite (debug, make db-init)
# libonig-dev    : bibliothèque Oniguruma → extension PHP mbstring (regex multibyte)
# python3 / venv / pip : runtime pour Semgrep
# ca-certificates : certificats TLS pour git clone HTTPS
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libicu-dev \
        libzip-dev \
        libsqlite3-dev \
        sqlite3 \
        libonig-dev \
        python3 \
        python3-pip \
        python3-venv \
        ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ── Extensions PHP ────────────────────────────────────────────────────────────
# Toutes les extensions requises par le cahier des charges SecureScan.
# intl nécessite une étape configure explicite.
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_sqlite \
        intl \
        zip \
        opcache \
        mbstring

# ── Semgrep dans un virtualenv Python isolé ───────────────────────────────────
# Pourquoi un venv ? Debian Bookworm impose PEP 668 : pip ne peut pas installer
# de packages dans l'environnement Python système sans --break-system-packages.
# Le venv isole proprement Semgrep et ses dépendances.
#
# POINT D'ATTENTION : Semgrep télécharge ses règles lors du premier scan
# (semgrep --config auto). En environnement sans accès internet, utiliser
# --config /chemin/vers/regles/locales.
ENV SEMGREP_VENV=/opt/semgrep-venv

RUN python3 -m venv "${SEMGREP_VENV}" \
    && "${SEMGREP_VENV}/bin/pip" install --no-cache-dir --upgrade pip \
    && "${SEMGREP_VENV}/bin/pip" install --no-cache-dir semgrep \
    # Symlink global pour que `semgrep` soit accessible dans tout le conteneur
    && ln -sf "${SEMGREP_VENV}/bin/semgrep" /usr/local/bin/semgrep

# ── Composer 2.x ──────────────────────────────────────────────────────────────
# On copie le binaire depuis l'image officielle Composer (COPY --from multi-stage)
# plutôt que de le télécharger via curl pour profiter du cache des couches.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ── Répertoire de travail ─────────────────────────────────────────────────────
WORKDIR /var/www/html

# ── Dossiers Symfony avec permissions ─────────────────────────────────────────
# Ces dossiers doivent être accessibles en écriture par www-data (uid=33).
# Ils sont créés ici pour que l'image de base soit cohérente.
RUN mkdir -p var/cache var/log var/sessions \
    && chown -R www-data:www-data /var/www/html

# ── Configuration PHP partagée ────────────────────────────────────────────────
COPY docker/php/php.ini "${PHP_INI_DIR}/conf.d/securescan.ini"

# Port PHP-FPM (utilisé par Nginx en upstream)
EXPOSE 9000


# =============================================================================
# Stage DEV — outils de développement, code monté via volume
# =============================================================================
FROM base AS dev

ENV APP_ENV=dev

# ── Xdebug ────────────────────────────────────────────────────────────────────
# xdebug.discover_client_host=true : essaie de détecter l'IP du client
# xdebug.client_host=host.docker.internal : fallback macOS/Windows
# Sur Linux : host.docker.internal n'est pas disponible nativement ;
# ajouter --add-host=host.docker.internal:host-gateway dans docker-compose.yml
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && { \
        echo "xdebug.mode=develop,coverage,debug"; \
        echo "xdebug.discover_client_host=true"; \
        echo "xdebug.client_host=host.docker.internal"; \
        echo "xdebug.client_port=9003"; \
        echo "xdebug.start_with_request=yes"; \
        echo "xdebug.log=/tmp/xdebug.log"; \
    } >> "${PHP_INI_DIR}/conf.d/docker-php-ext-xdebug.ini"

# Entrypoint DEV : installe Composer si vendor/ absent, ajuste les permissions
COPY docker/php/entrypoint-dev.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# NOTE : on ne fait PAS `USER www-data` ici car l'entrypoint doit pouvoir
# corriger les permissions (chown) — il tourne en root puis exec php-fpm.
# En DEV, le code est monté via volume (voir docker-compose.yml), pas copié.

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]


# =============================================================================
# Stage PROD — image allégée, code embarqué, optimisations activées
# =============================================================================
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

# ── Copier le code source ─────────────────────────────────────────────────────
# .dockerignore exclut : vendor/, var/cache, var/log, .git, tests/, *.md, etc.
COPY --chown=www-data:www-data . /var/www/html

# ── Dépendances Composer (production only) ────────────────────────────────────
RUN composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --prefer-dist \
        --no-interaction \
    && composer clear-cache

# ── Cache Symfony en production ───────────────────────────────────────────────
# Pré-chauffe le cache pour que le premier accès soit rapide
RUN APP_ENV=prod php bin/console cache:warmup --no-debug

# ── Permissions finales ───────────────────────────────────────────────────────
RUN chown -R www-data:www-data var/ \
    && find var/ -type d -exec chmod 775 {} \; \
    && find var/ -type f -exec chmod 664 {} \;

# ── OPcache durci pour la production ─────────────────────────────────────────
# validate_timestamps=0 : PHP ne vérifie plus le mtime des fichiers → gain perf
# memory_consumption=256 : augmenté car toutes les classes Symfony sont en cache
RUN { \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
    } >> "${PHP_INI_DIR}/conf.d/securescan.ini"

COPY docker/php/entrypoint-prod.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
