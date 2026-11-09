# Déploiement de compteurs

Même hébergeur et même méthode qu'artisandubenin (`artisandubenin/docs/11-deploiement/`) :
**Hostinger mutualisé (cPanel, accès SSH)**, PHP 8.4, MySQL 8, mise à jour par `git` puis un script
idempotent, tâches planifiées par le cron `schedule:run`, sauvegarde par une commande Artisan.

Différences avec artisandubenin :

| | artisandubenin | compteurs |
|---|---|---|
| Dépôt et CI | GitLab, sans job de déploiement | **GitHub** (`TIKMOS/compteurs`) et **GitHub Actions** : tests à chaque push, déploiement automatique de `main` si tout est vert |
| Assets Vite | compilés sur le poste, `public/build` commité | compilés par la CI et envoyés sur le serveur (`public/build` n'est plus commité) |
| Racine web | dépôt dans `public_html` + `.htaccess` qui réécrit vers `public/` (ADR-010) | **racine du domaine sur `public/`** : le reste du dépôt n'est jamais exposé |
| File d'attente | cron `queue:work` | aucune (rien n'est mis en file, `QUEUE_CONNECTION=sync`) |
| Sauvegarde | 7 jours, base + médias | base de données, **30 jours** (voir § 7 pour les photos) |

```
 git push main ──► GitHub Actions ──► tests (Pint, PHPStan, Pest, tests JS, build Vite)
                                         │ tout vert
                                         ▼
                        SSH Hostinger : assets → ~/compteurs/.deploy/build-incoming
                                        scripts/deploy.sh <commit>
                                         │
     maintenance → git (commit exact) → composer --no-dev → assets → droits
     → sauvegarde de la base → migrate --force → caches → remise en service
                                         │
                        vérification : https://<domaine>/up et /login
```

---

## 1. Ce qu'il faut avant de commencer

- Un hébergement Hostinger avec **cPanel et accès SSH** (comme artisandubenin).
- Un domaine ou un sous-domaine pour l'application, par exemple `compteurs.<votre-domaine>`.
- Un accès administrateur au dépôt GitHub `TIKMOS/compteurs`.

## 2. Disposition sur le serveur

```
/home/<utilisateur>/
├── compteurs/                ← dépôt Git (cloné une fois, mis à jour par scripts/deploy.sh)
│   ├── public/               ← RACINE WEB du domaine (seul dossier visible depuis Internet)
│   │   └── build/            ← assets compilés, déposés par la CI
│   ├── storage/
│   │   ├── app/private/      ← photos des décompteurs (jamais servies directement)
│   │   ├── backups/db/       ← sauvegardes quotidiennes de la base (30 jours)
│   │   └── logs/             ← journaux, un fichier par jour (14 jours)
│   ├── .deploy/              ← fichiers de travail du déploiement (assets, version précédente)
│   └── .env                  ← configuration de production (droits 600, jamais dans Git)
└── public_html/              ← non utilisé par compteurs
```

## 3. Première installation (une seule fois)

### 3.1 Dans cPanel

1. **Domaine** : *Domaines* → créer le sous-domaine (ou domaine supplémentaire) et régler sa
   **racine de document** sur `/home/<utilisateur>/compteurs/public`.
   > Si cPanel n'autorise pas ce réglage pour le domaine principal, utilisez un sous-domaine. En
   > dernier recours : remplacer `public_html` par un lien symbolique
   > (`ln -s ~/compteurs/public ~/public_html`, après avoir sauvegardé puis vidé `public_html`).
2. **PHP** : *Select PHP Version* → **8.4**, avec les extensions `bcmath, ctype, curl, dom, fileinfo,
   gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip, zlib, exif`.
   Réglages : `memory_limit = 256M`, `upload_max_filesize = 12M`, `post_max_size = 16M` (photos).
3. **MySQL** : *Bases de données MySQL* → créer la base (ex. `compteurs`), créer un utilisateur avec un
   mot de passe fort, puis **« Ajouter l'utilisateur à la base » avec TOUS LES PRIVILÈGES**
   (étape souvent oubliée, cf. dépannage d'artisandubenin).
4. **Email** : créer `no-reply@<votre-domaine>` (envoi des emails de vérification, mot de passe oublié,
   rappels de relevé).
5. **HTTPS** : *SSL/TLS Status* → lancer **AutoSSL** (certificat Let's Encrypt gratuit, renouvelé
   automatiquement) pour le domaine et `www.`. Le passage en HTTPS est ensuite forcé par
   `public/.htaccess` et par l'application (redirection 301 + en-tête HSTS).
6. **SSH** : *SSH Access* → activer l'accès et noter **hôte, port et utilisateur**.

### 3.2 Clés SSH

Deux clés différentes, sans phrase de passe, aucune n'est jamais commitée :

| Clé | Où est la partie privée | Où va la partie publique | Sert à |
|---|---|---|---|
| **Clé de déploiement GitHub** | sur le serveur (`~/.ssh/github_compteurs`) | GitHub → dépôt → *Settings → Deploy keys* (lecture seule) | `git fetch` depuis le serveur |
| **Clé CI** | secret GitHub `SSH_PRIVATE_KEY` | serveur → `~/.ssh/authorized_keys` (*cPanel → SSH Access → Manage keys → Import* puis *Authorize*) | connexion de GitHub Actions au serveur |

Sur le serveur (SSH) :

```bash
ssh-keygen -t ed25519 -N "" -C "hostinger-compteurs" -f ~/.ssh/github_compteurs
cat ~/.ssh/github_compteurs.pub        # → à coller dans GitHub, Deploy keys (sans « write access »)
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_compteurs
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

Sur votre ordinateur, pour la clé CI :

```bash
ssh-keygen -t ed25519 -N "" -C "github-actions-compteurs" -f compteurs_ci
# compteurs_ci.pub → autorisée sur le serveur ; compteurs_ci (privée) → secret SSH_PRIVATE_KEY,
# puis supprimez les deux fichiers de votre ordinateur.
ssh-keyscan -p <PORT> <HÔTE>           # → secret SSH_KNOWN_HOSTS
```

### 3.3 Le dépôt et le `.env`

```bash
cd ~
git clone git@github.com:TIKMOS/compteurs.git compteurs
cd compteurs
cp .env.production.example .env
chmod 600 .env
nano .env                  # remplir APP_URL, DB_*, MAIL_* (cf. § 9)
php artisan key:generate   # une seule fois, jamais à refaire (cf. § 9)
php -v                     # doit afficher 8.4 ; sinon notez le chemin, ex. /opt/alt/php84/usr/bin/php
```

### 3.4 Tâche cron (planificateur)

cPanel → *Tâches cron* → ajouter, **chaque minute** (`* * * * *`) :

```
cd /home/<utilisateur>/compteurs && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Remplacez `/usr/local/bin/php` par le PHP 8.4 noté ci-dessus si `php -v` n'affiche pas 8.4.
Le planificateur lance : la sauvegarde de la base (2 h 30), le rappel de relevé de fin de mois (8 h).

**File d'attente** : aucune n'est utilisée (`QUEUE_CONNECTION=sync`, les emails partent tout de
suite), donc pas de cron `queue:work`. Si une file est ajoutée un jour : `QUEUE_CONNECTION=database`
et un second cron chaque minute :
`cd ~/compteurs && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1`.

### 3.5 GitHub Actions

GitHub → dépôt → *Settings → Secrets and variables → Actions*.

**Secrets** (onglet *Secrets*, jamais visibles une fois enregistrés) :

| Nom | Valeur |
|---|---|
| `SSH_HOST` | hôte SSH Hostinger |
| `SSH_PORT` | port SSH (souvent 65002 ou 22) |
| `SSH_USER` | utilisateur SSH |
| `SSH_PRIVATE_KEY` | clé privée CI (`compteurs_ci`, tout le fichier) |
| `SSH_KNOWN_HOSTS` | sortie de `ssh-keyscan` |
| `DEPLOY_PATH` | `/home/<utilisateur>/compteurs` |

**Variables** (onglet *Variables*) :

| Nom | Valeur |
|---|---|
| `APP_URL` | `https://compteurs.<votre-domaine>` (vérification après déploiement) |
| `PHP_BIN` | facultatif : chemin du PHP 8.4 si `php` n'est pas en 8.4 |
| `DEPLOY_ENABLED` | `true` **une fois les étapes 3.1 à 3.4 faites** ; avant, la CI s'arrête aux tests |

Le déploiement ne tourne que sur `main`, après un push, et seulement si le job de tests est vert ;
jamais sur une pull request. Les secrets ne sont transmis qu'à ce job.

### 3.6 Premier déploiement

1. Mettez `DEPLOY_ENABLED` à `true`.
2. GitHub → *Actions* → *CI / déploiement* → *Run workflow* sur `main` (ou poussez un commit).
3. Le job « Déploiement » installe les dépendances, crée les tables (`migrate --force`), met les
   caches, puis vérifie `https://<domaine>/up` et `/login`.
4. Ouvrez le site, créez votre compte propriétaire et validez l'email reçu.

## 4. Configuration serveur : récapitulatif

| Point | Où | Réglage |
|---|---|---|
| Racine web | cPanel, domaine | `~/compteurs/public` |
| HTTPS | cPanel *SSL/TLS Status* (AutoSSL, Let's Encrypt) | + redirection 301 et HSTS : `public/.htaccess` et `App\Http\Middleware\ForceHttps` |
| En-têtes de sécurité, compression, cache | `public/.htaccess` | `nosniff`, `SAMEORIGIN`, gzip, assets versionnés cachés 1 an, `sw.js` jamais caché |
| Fichiers cachés (`.env`, `.git`) | `public/.htaccess` + racine sur `public/` | 403, et de toute façon hors racine web |
| Droits | `scripts/deploy.sh` | `storage/` et `bootstrap/cache/` inscriptibles par le compte, `.env` en 600 |
| Planificateur | cron chaque minute | `schedule:run` |
| File d'attente | — | aucune (`sync`) |
| Journaux | `.env` | un fichier par jour, 14 jours, niveau `warning` |

## 5. Ce que fait `scripts/deploy.sh`

Lancé par la CI (ou à la main, § 6), dans cet ordre ; un échec arrête tout :

1. **Vérifications** : `.env` présent, `APP_DEBUG=false`, `APP_KEY` remplie, PHP ≥ 8.4, version cible
   connue, assets compilés disponibles.
2. **Mode maintenance** (`artisan down`) : les visiteurs voient « Maintenance en cours ».
3. **Code** : `git fetch` puis `git reset --hard <commit>` : exactement le commit testé par la CI.
4. **Dépendances** : `composer install --no-dev --optimize-autoloader`.
5. **Assets** : les assets compilés par la CI remplacent `public/build` (les précédents sont gardés
   dans `.deploy/build-previous` pour un retour arrière).
6. **Droits** sur `storage/`, `bootstrap/cache/` et `.env`.
7. **Sauvegarde de la base** (`compteurs:sauvegarde --motif=avant-deploiement`).
8. **Migrations** : `artisan migrate --force`.
9. **Caches** : `config:cache`, `route:cache`, `view:cache`, `event:cache`.
10. **Remise en service** (`artisan up`).

En cas d'échec après l'étape 2, **le site reste en maintenance** et le script affiche la commande de
retour arrière (§ 8). Le script a été essayé de bout en bout sur une copie locale : premier
déploiement, mise à jour avec migration, retour arrière, migration en échec puis retour arrière.

## 6. Déployer à la main (sans GitHub Actions)

Hostinger n'a pas Node : les assets se compilent sur votre ordinateur.

```bash
# Sur votre ordinateur, à jour de main
npm ci && npm run build
scp -P <PORT> -r public/build <UTILISATEUR>@<HÔTE>:compteurs/.deploy/build-incoming

# Sur le serveur
cd ~/compteurs && bash scripts/deploy.sh               # dernière version de main
cd ~/compteurs && bash scripts/deploy.sh <commit>      # ou une version précise
```

Si `php` n'est pas en 8.4 : `PHP_BIN=/opt/alt/php84/usr/bin/php bash scripts/deploy.sh`.

## 7. Sauvegardes

- **Automatique** : chaque jour à 2 h 30 (heure du Bénin), `php artisan compteurs:sauvegarde --jours=30`.
  Fichier `storage/backups/db/compteurs_AAAA-MM-JJ_HHMMSS.sql.gz`, droits 600. Les fichiers de plus de
  **30 jours** sont supprimés. Une sauvegarde supplémentaire (`…_avant-deploiement.sql.gz`) est faite
  à chaque déploiement, juste avant les migrations.
- **Méthode** : `mysqldump` ; s'il est absent ou interdit par l'hébergeur, dump en PHP pur. Les deux
  ont été restaurés à l'identique sur MySQL 8 (Laragon) pendant la mise au point.
- **Vérifier** : `ls -lh ~/compteurs/storage/backups/db/` (un fichier par jour) ; en cas d'échec, la
  cause est dans `storage/logs/laravel-AAAA-MM-JJ.log`.
- **Restaurer** :

  ```bash
  cd ~/compteurs
  php artisan down
  gunzip -c storage/backups/db/compteurs_AAAA-MM-JJ_HHMMSS.sql.gz | mysql -u <UTILISATEUR_BD> -p <BASE>
  php artisan up
  ```

- **Hors du serveur** : ces sauvegardes sont sur le même disque que la base. Récupérez-les
  régulièrement (au moins chaque semaine) sur un autre support :
  `scp -P <PORT> -r <UTILISATEUR>@<HÔTE>:compteurs/storage/backups/db ./sauvegardes-compteurs`.
- **Photos des décompteurs** (`storage/app/private`) : non incluses dans la sauvegarde quotidienne de
  la base. Selon l'offre, l'hébergeur fait ses propres sauvegardes du compte, mais ne comptez pas
  dessus seulement : copiez aussi ce dossier avec la commande `scp` ci-dessus.

## 8. Retour arrière

**Le code** (version précédente, avec ses assets) :

```bash
cd ~/compteurs && bash scripts/deploy.sh --retour-arriere
```

Le script revient au commit déployé juste avant (`.deploy/previous_sha`), remet les assets
précédents, refait les caches et rouvre le site. Pour revenir à une version plus ancienne : sur
GitHub → *Actions*, ouvrir l'exécution de ce commit et *Re-run jobs* (assets gardés 7 jours), ou
§ 6 avec ce commit.

**La base** : les migrations ne sont **pas** annulées automatiquement (risque de perte de données).
Si la version annulée avait ajouté des migrations :

- elles n'ont fait qu'**ajouter** des tables ou colonnes : rien à faire, l'ancienne version les ignore ;
- elles ont **modifié ou supprimé** des données : restaurer la sauvegarde `…_avant-deploiement.sql.gz`
  faite juste avant (§ 7). Les saisies faites depuis ce déploiement sont alors perdues : notez-les avant ;
- cas simple et sûr : `php artisan migrate:rollback --step=1` (une migration à la fois).

**Rouvrir le site** resté en maintenance après un échec, sans rien changer : `php artisan up`.

## 9. Variables `.env` de production

Modèle : `.env.production.example`. Les valeurs secrètes ne sont **que** dans le `.env` du serveur.

| Variable | À remplir | Remarque |
|---|---|---|
| `APP_KEY` | par `php artisan key:generate` | **ne jamais la changer** : cela déconnecte tout le monde et invalide les liens WhatsApp envoyés |
| `APP_URL` | `https://compteurs.<votre-domaine>` | utilisée dans les emails et les liens signés |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | le déploiement refuse `APP_DEBUG=true` (afficherait le code et les mots de passe) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | base et utilisateur MySQL créés en § 3.1 | `DB_HOST=localhost` |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | boîte `no-reply@…` créée en § 3.1 | SMTP Hostinger, port 465 |
| `SESSION_SECURE_COOKIE` | `true` (déjà) | cookie de session seulement en HTTPS |
| `LOG_LEVEL` / `LOG_STACK` | `warning` / `daily` (déjà) | |
| `QUEUE_CONNECTION` | `sync` (déjà) | voir § 3.4 |

Les secrets de la CI (accès SSH) sont dans GitHub (§ 3.5), jamais dans le dépôt.

## 10. Vérifications et dépannage

Après chaque déploiement (la CI fait les deux premières) :

- [ ] `https://<domaine>/up` répond 200
- [ ] `https://<domaine>/login` répond 200 ; `http://<domaine>/login` redirige en `https://`
- [ ] `https://<domaine>/.env` → 403 ou 404
- [ ] connexion, ouverture d'une maison, d'un résultat de mois et d'une fiche PDF
- [ ] le lendemain : une nouvelle sauvegarde dans `storage/backups/db/`

| Symptôme | Cause probable | Correction |
|---|---|---|
| Le job de déploiement ne démarre pas | `DEPLOY_ENABLED` absent ou ≠ `true`, ou commit hors `main` | § 3.5 |
| `Permission denied (publickey)` dans la CI | clé CI non autorisée sur le serveur | cPanel → SSH Access → Manage keys → *Authorize* |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` vide ou ancien | refaire `ssh-keyscan -p <PORT> <HÔTE>` |
| `git fetch` refusé sur le serveur | clé de déploiement absente de GitHub | § 3.2 |
| « PHP 8.4 requis » | `php` du terminal en autre version | variable `PHP_BIN` (CI) ou `PHP_BIN=…` (à la main) |
| Erreur 500 partout, « Access denied for user » | utilisateur MySQL non lié à la base | cPanel → *Ajouter l'utilisateur à la base* + tous les privilèges |
| Erreur 500 après un changement de `.env` | configuration en cache | `php artisan config:cache` |
| Page « Maintenance en cours » qui reste | déploiement interrompu | lire le message du job, puis § 8 |
| Pas de sauvegarde quotidienne | cron absent ou mauvais chemin PHP | § 3.4 ; tester `php artisan compteurs:sauvegarde` à la main |
| Pas d'email reçu | identifiants SMTP | `MAIL_*` puis `php artisan config:cache` |
