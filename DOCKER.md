# SecureScan — Guide Docker

## Démarrage rapide (3 commandes)

```bash
# 1. Construire les images Docker (≈ 5–10 min au premier lancement)
make build

# 2. Démarrer les services en arrière-plan
make up

# 3. Initialiser la base de données SQLite
make db-init
```

L'application est disponible sur **http://localhost:8000**

---

## Prérequis

| Outil | Version minimum | Vérification |
|-------|----------------|--------------|
| Docker | 20.10+ | `docker --version` |
| Docker Compose | v2 (plugin) | `docker compose version` |
| make | 3.81+ | `make --version` |

> **Windows** : utiliser WSL2 (Ubuntu recommandé). Stocker le projet dans
> `~/projects/securescan` (filesystem WSL2) et **non** dans `/mnt/c/`
> pour éviter les problèmes de performances et de permissions.

---

## Architecture des conteneurs

```
┌─────────────────────────────────────────────────┐
│   navigateur                               │
└───────────────┬─────────────────────────────────┘
                │ http://localhost:8000
                ▼
┌─────────────────────────────────────────────────┐
│  nginx:1.25-alpine (securescan-nginx)           │
│  • Sert les assets statiques (CSS, JS, images) │
│  • Passe les requêtes PHP en FastCGI →          │
└───────────────┬─────────────────────────────────┘
                │ app:9000 (FastCGI)
                ▼
┌─────────────────────────────────────────────────┐
│  PHP 8.2-FPM + Semgrep (securescan-app)        │
│                                                 │
│  ┌──────────────────┐  ┌─────────────────────┐ │
│  │ Symfony 6.x      │  │ Semgrep (Python)    │ │
│  │ (Twig, Doctrine) │  │ lancé via Process   │ │
│  └──────────────────┘  └─────────────────────┘ │
│                                                 │
│  var/securescan.db (SQLite)                     │
│  /tmp/securescan-scans/ (repos clonés)          │
└─────────────────────────────────────────────────┘
```

**Pourquoi PHP et Semgrep dans le même conteneur ?**
Symfony lance Semgrep via le composant `symfony/process` (appel CLI local).
Un conteneur Semgrep séparé nécessiterait un appel réseau et du marshalling JSON,
ajoutant de la complexité sans bénéfice architectural.

---

## Variables d'environnement

Créez un fichier `.env.local` à la racine du projet (ignoré par Git) :

```dotenv
# .env.local — valeurs locales, jamais commitées
APP_ENV=dev
APP_SECRET=votre_secret_de_32_caracteres_minimum
DATABASE_URL=sqlite:///%kernel.project_dir%/var/securescan.db
APP_PORT=8000          # Port hôte pour accéder à l'app

# Désactiver la télémétrie Semgrep
SEMGREP_SEND_METRICS=off

# Activer Xdebug à la demande (off | develop | debug | coverage)
XDEBUG_MODE=off
```

---

## Commandes courantes

### Cycle de vie Docker

```bash
make build          # Construire les images
make build-no-cache # Forcer la reconstruction complète (après changement du Dockerfile)
make up             # Démarrer en arrière-plan
make up-logs        # Démarrer et voir les logs
make down           # Arrêter les conteneurs
make restart        # Redémarrer
make status         # État des conteneurs
make logs           # Logs en temps réel (tous les services)
make logs-app       # Logs PHP-FPM uniquement
make logs-nginx     # Logs Nginx uniquement
```

### Développement

```bash
make bash                              # Shell dans le conteneur
make console CMD="cache:clear"         # Vider le cache Symfony
make console CMD="debug:router"        # Lister les routes
make console CMD="doctrine:schema:update --force"
```

### Base de données SQLite

```bash
make db-init    # Créer le schéma (première fois)
make db-update  # Mettre à jour le schéma
make db-drop    # Supprimer les tables (avec confirmation)
make db-shell   # Ouvrir sqlite3 en interactif
```

### Semgrep

```bash
make scan-version   # Vérifier la version installée
make scan           # Lancer un scan test sur src/
```

### Nettoyage

```bash
make clean-scans    # Supprimer les repos clonés dans /tmp/
make clean          # Tout supprimer, y compris les volumes (avec confirmation)
```

---

## Vérifications post-démarrage

Après `make up`, vérifiez que tout fonctionne :

```bash
make check
```

Ce qui doit apparaître :
```
=== PHP ===
PHP 8.2.x (fpm-fcgi) ...

=== Semgrep ===
1.x.x

=== Git ===
git version 2.x.x

=== SQLite ===
3.x.x

=== Symfony Console ===
Symfony 6.x.x

=== Connectivité réseau ===
# Hash du dernier commit Symfony (preuve que git clone fonctionne)
```

---

## Notes par système d'exploitation

### Windows (WSL2)

1. **Activer WSL2** avec Ubuntu : `wsl --install -u Ubuntu`
2. **Stocker le projet dans WSL2** (`~/projects/securescan`), PAS dans `/mnt/c/`
3. **Docker Desktop** : Settings → Resources → WSL Integration → activer Ubuntu
4. **Permissions** : si des erreurs `EACCES` apparaissent, l'entrypoint les corrige
   automatiquement via `chmod 777 var/`
5. **`make`** : installer via `sudo apt install make` dans WSL2
6. **Port 8000** : accessible depuis Windows sur `http://localhost:8000`
   (Docker Desktop redirige automatiquement depuis WSL2)

### macOS (Intel + Apple Silicon)

1. **VirtioFS** : Docker Desktop → Settings → General → "Use VirtioFS"
   améliore significativement les performances des volumes montés
2. **Apple Silicon (M1/M2/M3)** : l'image `php:8.2-fpm` est multi-arch.
   Semgrep fournit des wheels natifs `arm64` depuis la v1.50.
3. **Xdebug** : `host.docker.internal` est résolu automatiquement par Docker Desktop

### Linux

1. **Docker Engine** (pas Docker Desktop) : installer via apt/yum
2. **`docker compose`** v2 : inclus dans Docker Engine 20.10+
   (si vous avez `docker-compose` avec tiret, mettre à jour)
3. **Xdebug** : `host.docker.internal` n'est pas résolu automatiquement.
   Décommenter dans `docker-compose.yml` :
   ```yaml
   extra_hosts:
     - "host.docker.internal:host-gateway"
   ```
4. **Permissions** : votre utilisateur doit être dans le groupe `docker` :
   `sudo usermod -aG docker $USER` (puis se reconnecter)

---

## Mode Production

Pour construire et démarrer en mode production :

```bash
# 1. Configurer les variables d'environnement de production
cp .env .env.prod.local
# Éditer .env.prod.local avec les vraies valeurs

# 2. Construire l'image PROD (code embarqué, dépendances optimisées)
APP_SECRET=$(php -r "echo bin2hex(random_bytes(32));") \
  docker compose build --build-arg target=prod

# 3. Démarrer
docker compose -f docker-compose.yml up -d
```

Différences avec le mode DEV :
- Le code est **copié dans l'image** (pas de volume monté)
- `composer install --no-dev` (pas de dépendances de développement)
- OPcache : `validate_timestamps=0` (PHP ne vérifie plus le mtime des fichiers)
- Xdebug **non installé**
- `APP_SECRET` invalide → l'entrypoint refuse de démarrer

---

## Structure des fichiers Docker

```
securescan/
├── Dockerfile                    # Multi-stage : base → dev → prod
├── docker-compose.yml            # Services app + nginx
├── .dockerignore                 # Fichiers exclus du contexte de build
├── Makefile                      # Commandes raccourcis
├── DOCKER.md                     # Ce fichier
└── docker/
    ├── nginx/
    │   └── default.conf          # Config Nginx pour Symfony
    └── php/
        ├── php.ini               # Config PHP personnalisée
        ├── entrypoint-dev.sh     # Entrypoint stage DEV
        └── entrypoint-prod.sh    # Entrypoint stage PROD
```

---

## Dépannage fréquent

### `permission denied` sur `var/`

```bash
# Dans le conteneur
make bash
chmod -R 777 var/
```

### Port 8000 déjà utilisé

```bash
# Changer le port dans .env.local
APP_PORT=8080
make restart
```

### Semgrep télécharge les règles à chaque scan

Normal au premier scan. Les règles sont ensuite mises en cache dans
`/tmp/semgrep-cache` (volume persisté entre les redémarrages en DEV).

### `vendor/` absent après `make up`

L'entrypoint installe Composer automatiquement si `vendor/autoload.php` est absent.
Si l'installation échoue, vérifier les logs :

```bash
make logs-app
```

### SQLite `database is locked`

SQLite n'est pas conçu pour la concurrence. SecureScan utilise des scans
séquentiels, donc ce problème ne devrait pas apparaître. Si c'est le cas,
redémarrer le conteneur : `make restart`.
