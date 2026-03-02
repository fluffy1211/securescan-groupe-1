# =============================================================================
# SecureScan — Makefile
#
# Raccourcis pour les commandes Docker et Symfony les plus fréquentes.
#
# Pré-requis :
#   - Docker 20.10+ avec BuildKit activé (défaut depuis Docker 23)
#   - Docker Compose v2 (commande : docker compose, SANS tiret)
#
# POINT D'ATTENTION — Windows :
#   make n'est pas disponible nativement sur Windows. Options :
#   1. WSL2 (recommandé) : installer make via `sudo apt install make`
#   2. Git Bash + make : télécharger depuis ezwinports.sourceforge.net
#   3. Chocolatey : `choco install make`
#   4. Utiliser directement les commandes `docker compose` en substitution.
#
# POINT D'ATTENTION — macOS :
#   make est disponible via les Xcode Command Line Tools :
#   `xcode-select --install`
# =============================================================================

# ── Variables ─────────────────────────────────────────────────────────────────
COMPOSE         := docker compose
APP_CONTAINER   := securescan-app
NGINX_CONTAINER := securescan-nginx

# Couleurs pour l'affichage
BOLD  := \033[1m
RESET := \033[0m
GREEN := \033[32m
CYAN  := \033[36m
YELLOW:= \033[33m

.DEFAULT_GOAL := help

# ── Phony targets (ne correspondent pas à des fichiers) ───────────────────────
.PHONY: help build build-no-cache up up-logs down restart \
        bash console scan scan-version \
        db-init db-update db-drop db-shell \
        composer-install composer-update \
        cache-clear cache-warmup \
        logs logs-app logs-nginx \
        status check clean clean-scans \
        prod-build

# =============================================================================
# Aide
# =============================================================================

help: ## Afficher cette aide
	@echo ""
	@echo "  $(BOLD)SecureScan — Commandes Make$(RESET)"
	@echo ""
	@echo "  $(CYAN)Docker$(RESET)"
	@grep -E '^(build|build-no-cache|up|up-logs|down|restart|status|logs|logs-app|logs-nginx|clean|prod-build):.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Développement$(RESET)"
	@grep -E '^(bash|console|check):.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Base de données$(RESET)"
	@grep -E '^db-.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Semgrep$(RESET)"
	@grep -E '^(scan|scan-version):.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Composer$(RESET)"
	@grep -E '^composer-.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Cache$(RESET)"
	@grep -E '^cache-.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)Nettoyage$(RESET)"
	@grep -E '^clean.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  Exemple : $(BOLD)make console CMD=\"doctrine:schema:create\"$(RESET)"
	@echo ""

# =============================================================================
# Docker Compose — cycle de vie
# =============================================================================

build: ## Construire les images Docker (utilise le cache)
	$(COMPOSE) build

build-no-cache: ## Reconstruire les images sans cache (force reinstall de tout)
	$(COMPOSE) build --no-cache

up: ## Démarrer tous les services en arrière-plan
	$(COMPOSE) up -d
	@echo ""
	@echo "  $(GREEN)Services démarrés !$(RESET)"
	@echo "  Application : http://localhost:$${APP_PORT:-8000}"
	@echo ""

up-logs: ## Démarrer les services et afficher les logs en temps réel
	$(COMPOSE) up

down: ## Arrêter et supprimer les conteneurs (les volumes sont conservés)
	$(COMPOSE) down

restart: ## Redémarrer tous les services
	$(COMPOSE) restart

status: ## Afficher l'état des conteneurs et les ports exposés
	$(COMPOSE) ps

logs: ## Suivre les logs de tous les services (Ctrl+C pour quitter)
	$(COMPOSE) logs -f

logs-app: ## Suivre uniquement les logs PHP-FPM
	$(COMPOSE) logs -f app

logs-nginx: ## Suivre uniquement les logs Nginx
	$(COMPOSE) logs -f nginx

prod-build: ## Construire l'image de PRODUCTION (stage prod du Dockerfile)
	$(COMPOSE) build --build-arg target=prod
	@echo "$(YELLOW)Pour déployer, pousser l'image vers votre registry.$(RESET)"

# =============================================================================
# Développement
# =============================================================================

bash: ## Ouvrir un shell Bash interactif dans le conteneur app
	$(COMPOSE) exec $(APP_CONTAINER) bash

# Utilisation : make console CMD="cache:clear"
#               make console CMD="doctrine:schema:create"
#               make console CMD="debug:router"
console: ## Exécuter une commande Symfony bin/console (ex: make console CMD="cache:clear")
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console $(CMD)

check: ## Vérifier que PHP, Semgrep, Git et SQLite fonctionnent dans le conteneur
	@echo "$(BOLD)=== PHP ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) php --version
	@echo ""
	@echo "$(BOLD)=== Semgrep ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) semgrep --version
	@echo ""
	@echo "$(BOLD)=== Git ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) git --version
	@echo ""
	@echo "$(BOLD)=== SQLite ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) sqlite3 --version
	@echo ""
	@echo "$(BOLD)=== Symfony Console ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console --version
	@echo ""
	@echo "$(BOLD)=== Connectivité réseau (git clone) ===$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) git ls-remote --heads https://github.com/symfony/symfony.git HEAD
	@echo "$(GREEN)Tout fonctionne !$(RESET)"

# =============================================================================
# Base de données SQLite
# =============================================================================

db-init: ## Créer le schéma Doctrine SQLite (var/securescan.db)
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console doctrine:schema:create --env=dev
	@echo "$(GREEN)Base de données créée : var/securescan.db$(RESET)"

db-update: ## Mettre à jour le schéma sans perte de données (attention en prod)
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console doctrine:schema:update --force --env=dev

db-drop: ## Supprimer le schéma SQLite (ATTENTION : irréversible)
	@echo "$(YELLOW)ATTENTION : Cette opération supprime toutes les données !$(RESET)"
	@read -p "Confirmer ? [y/N] " confirm && [ "$$confirm" = "y" ]
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console doctrine:schema:drop --full-database --force --env=dev

db-shell: ## Ouvrir le client SQLite interactif sur var/securescan.db
	$(COMPOSE) exec $(APP_CONTAINER) sqlite3 var/securescan.db

# =============================================================================
# Semgrep
# =============================================================================

scan: ## Lancer Semgrep sur src/ et afficher les 10 premiers résultats JSON
	$(COMPOSE) exec $(APP_CONTAINER) semgrep --version
	@echo "$(BOLD)Lancement du scan Semgrep sur src/...$(RESET)"
	$(COMPOSE) exec $(APP_CONTAINER) \
		semgrep --config auto src/ --json --no-rewrite-rule-ids 2>/dev/null \
		| head -c 2000 || true
	@echo ""
	@echo "$(GREEN)Scan terminé. Voir les résultats dans l'interface web.$(RESET)"

scan-version: ## Afficher la version de Semgrep installée dans le conteneur
	$(COMPOSE) exec $(APP_CONTAINER) semgrep --version

# =============================================================================
# Composer
# =============================================================================

composer-install: ## Installer les dépendances PHP (avec cache Docker)
	$(COMPOSE) exec $(APP_CONTAINER) composer install --prefer-dist

composer-update: ## Mettre à jour les dépendances PHP
	$(COMPOSE) exec $(APP_CONTAINER) composer update

# =============================================================================
# Cache Symfony
# =============================================================================

cache-clear: ## Vider le cache Symfony (APP_ENV=dev)
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console cache:clear

cache-warmup: ## Préchauffer le cache Symfony (APP_ENV=dev)
	$(COMPOSE) exec $(APP_CONTAINER) php bin/console cache:warmup

# =============================================================================
# Nettoyage
# =============================================================================

clean: ## Arrêter les conteneurs ET supprimer les volumes (DONNÉES PERDUES)
	@echo "$(YELLOW)ATTENTION : Les volumes Docker seront supprimés (base SQLite, cache Composer).$(RESET)"
	@read -p "Confirmer ? [y/N] " confirm && [ "$$confirm" = "y" ]
	$(COMPOSE) down -v
	@echo "$(GREEN)Nettoyage terminé.$(RESET)"

clean-scans: ## Supprimer les repos Git clonés temporairement dans /tmp/securescan-scans/
	$(COMPOSE) exec $(APP_CONTAINER) \
		find /tmp/securescan-scans -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + 2>/dev/null || true
	@echo "$(GREEN)Fichiers temporaires de scan supprimés.$(RESET)"
