#!/usr/bin/env bash
#
# Déploiement de compteurs sur le serveur (Hostinger, SSH) — docs/DEPLOIEMENT.md.
# Même principe que scripts/post-deploy.sh d'artisandubenin : idempotent, une étape après l'autre.
#
#   Mise à jour vers la dernière version de main :   bash scripts/deploy.sh
#   Vers une version précise :                       bash scripts/deploy.sh <sha>
#   Retour à la version précédente :                 bash scripts/deploy.sh --retour-arriere
#
# Lancé par GitHub Actions (.github/workflows/ci.yml) :
#   ssh … "APP_DIR=~/compteurs bash -s -- <sha>" < scripts/deploy.sh
#
# Les assets (public/build) sont compilés par la CI, Hostinger n'ayant pas Node : la CI les dépose
# dans .deploy/build-incoming avant de lancer ce script. Sans CI, voir docs/DEPLOIEMENT.md § 6.
#
# Variables facultatives : APP_DIR (dossier de l'application), PHP_BIN, COMPOSER_BIN,
# MYSQLDUMP_BIN (chemins des programmes s'ils ne sont pas dans le PATH).

set -euo pipefail

# La mise à jour du code réécrit ce fichier pendant qu'il s'exécute : on travaille sur une copie.
if [ -z "${DEPLOY_COPY:-}" ] && [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    export APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
    copy="$(mktemp)"
    cp "${BASH_SOURCE[0]}" "$copy"
    DEPLOY_COPY=1 exec bash "$copy" "$@"
fi

: "${APP_DIR:?APP_DIR doit indiquer le dossier de l application (ex. ~/compteurs)}"
PHP="${PHP_BIN:-php}"
BRANCH="main"

# Composer tourne avec le même PHP que l'application (sur cPanel, `php` peut être une autre version).
composer_run() {
    local bin
    bin="$(command -v "${COMPOSER_BIN:-composer}" || true)"
    [ -n "$bin" ] || fail "composer introuvable : indiquez son chemin dans COMPOSER_BIN."
    if head -n 1 "$bin" | grep -q php; then
        "$PHP" "$bin" "$@"
    else
        "$bin" "$@"
    fi
}

MODE="deploiement"
TARGET=""
for arg in "$@"; do
    case "$arg" in
        --retour-arriere) MODE="retour-arriere" ;;
        -*) echo "Option inconnue : $arg" >&2; exit 2 ;;
        *) TARGET="$arg" ;;
    esac
done

STEP="préparation"
step() { STEP="$1"; printf '\n\033[1;34m▶ %s\033[0m\n' "$1"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\n\033[1;31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

MAINTENANCE=0
on_error() {
    local rc=$?
    [ "$rc" -eq 0 ] && return 0
    printf "\n\033[1;31m✗ Échec pendant l'étape « %s » (code %s).\033[0m\n" "$STEP" "$rc" >&2
    if [ "$MAINTENANCE" = 1 ]; then
        {
            printf 'Le site reste en MAINTENANCE (page « Maintenance en cours »).\n'
            printf '  - Revenir à la version précédente :  cd %s && bash scripts/deploy.sh --retour-arriere\n' "$APP_DIR"
            printf '  - Ou corriger, puis relancer le déploiement.\n'
            printf '  - Rouvrir le site malgré tout :      cd %s && %s artisan %s\n' "$APP_DIR" "$PHP" up
        } >&2
    fi
}
trap on_error EXIT

cd "$APP_DIR"
mkdir -p .deploy

step "Vérifications"
[ -f .env ] || fail ".env absent dans $APP_DIR : copiez .env.production.example puis remplissez-le (docs/DEPLOIEMENT.md § 3)."
if grep -qE '^APP_DEBUG=true' .env; then fail "APP_DEBUG=true dans .env : mettez APP_DEBUG=false en production."; fi
if ! grep -qE '^APP_KEY=base64:' .env; then fail "APP_KEY vide dans .env : lancez une fois « $PHP artisan key:generate »."; fi
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || fail "PHP 8.4 requis (actuel : $("$PHP" -r 'echo PHP_VERSION;')). Choisissez PHP 8.4 dans cPanel, ou indiquez PHP_BIN."
CURRENT="$(git rev-parse HEAD)"

if [ "$MODE" = "retour-arriere" ]; then
    [ -s .deploy/previous_sha ] || fail "Aucune version précédente enregistrée (.deploy/previous_sha)."
    TARGET="$(cat .deploy/previous_sha)"
else
    git fetch --quiet origin "$BRANCH"
    TARGET="${TARGET:-$(git rev-parse "origin/$BRANCH")}"
fi
git cat-file -e "${TARGET}^{commit}" 2>/dev/null || fail "Version $TARGET introuvable dans le dépôt."
ok "version actuelle ${CURRENT:0:8} → cible ${TARGET:0:8} ($MODE)"

if [ "$MODE" = "deploiement" ] && [ ! -f .deploy/build-incoming/manifest.json ] && [ ! -f public/build/manifest.json ]; then
    fail "Assets compilés absents : la CI dépose public/build dans .deploy/build-incoming (docs/DEPLOIEMENT.md § 6)."
fi

step "Mode maintenance"
"$PHP" artisan down --retry=60 >/dev/null 2>&1 || true
MAINTENANCE=1
ok "visiteurs : page « Maintenance en cours »"

step "Code"
git reset --quiet --hard "$TARGET"
if [ "$CURRENT" != "$TARGET" ]; then echo "$CURRENT" > .deploy/previous_sha; fi
echo "$TARGET" > .deploy/current_sha
ok "$(git log -1 --format='%h %s')"

step "Dépendances PHP (sans les paquets de développement)"
composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress
"$PHP" artisan config:clear >/dev/null
"$PHP" artisan route:clear >/dev/null
"$PHP" artisan view:clear >/dev/null
"$PHP" artisan event:clear >/dev/null
ok "vendor à jour"

step "Assets compilés (public/build)"
if [ "$MODE" = "retour-arriere" ]; then
    if [ -d .deploy/build-previous ]; then
        rm -rf .deploy/build-swap
        [ -d public/build ] && mv public/build .deploy/build-swap
        mv .deploy/build-previous public/build
        [ -d .deploy/build-swap ] && mv .deploy/build-swap .deploy/build-previous
        ok "assets de la version précédente remis en place"
    fi
elif [ -f .deploy/build-incoming/manifest.json ]; then
    rm -rf .deploy/build-previous
    [ -d public/build ] && mv public/build .deploy/build-previous
    mv .deploy/build-incoming public/build
    ok "nouveaux assets installés (les précédents sont gardés pour un retour arrière)"
else
    ok "assets inchangés"
fi
[ -f public/build/manifest.json ] || fail "public/build/manifest.json absent."

step "Droits sur storage et bootstrap/cache"
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R u+rwX,go-w storage bootstrap/cache
chmod 600 .env
ok "storage et bootstrap/cache inscriptibles, .env lisible par vous seul"

step "Sauvegarde de la base avant migration"
"$PHP" artisan compteurs:sauvegarde --jours=30 --motif=avant-deploiement ${MYSQLDUMP_BIN:+--mysqldump="$MYSQLDUMP_BIN"}

if [ "$MODE" = "deploiement" ]; then
    step "Migrations"
    "$PHP" artisan migrate --force
else
    step "Migrations (retour arrière)"
    printf '  \033[33m⚠\033[0m Les migrations ne sont PAS annulées automatiquement (risque de perte de données).\n'
    printf '    Si la version annulée en ajoutait, voir docs/DEPLOIEMENT.md § 8.\n'
fi

step "Caches de production"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

step "Remise en service"
"$PHP" artisan up
MAINTENANCE=0
ok "site ouvert"

printf '\n\033[1;32m✔ %s terminé : %s\033[0m\n' "$MODE" "$(git log -1 --format='%h %s')"
