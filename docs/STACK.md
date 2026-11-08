# STACK — référence technique héritée de `artisandubenin`

> Analyse en lecture seule du projet `./artisandubenin` (branche `fix/securite-liberation-throttle`,
> 139 commits) effectuée le 2026-11-07. Les versions indiquées sont celles **réellement installées**
> (`composer.lock` / `package-lock.json`), la contrainte déclarée est entre parenthèses.
>
> ⚠️ La documentation interne (`docs/01-architecture/01-stack-technique.md`, `docs/09-tests/`)
> n'est plus à jour : elle annonce Laravel 12, Pest 3, Vite 6 et spatie/laravel-model-states,
> alors que le code utilise Laravel 13, Pest 4, Vite 8 et des machines d'états maison.
> **Ce fichier se base sur le code, pas sur la doc.**

---

## 1. Langage et framework backend

| Brique | Version installée | Contrainte | Remarque |
|---|---|---|---|
| PHP | 8.3 (cible) | `^8.3` + `config.platform.php = 8.3.0` | PHP local du poste : 8.4.26. Le `platform` force la résolution des dépendances pour 8.3 (prod Hostinger). |
| Laravel | **13.15.0** | `^13.8` | Structure « slim » Laravel 11+ (`bootstrap/app.php`, `bootstrap/providers.php`, pas de `Kernel`). |
| Laravel Tinker | 3.0.2 | `^3.0` | |
| Composer | 2.8.6 | — | |
| Node.js | 22 (`.nvmrc`) | — | Uniquement pour le build Vite, **jamais en runtime serveur**. Poste local : Node 24. |

Scripts Composer utiles : `composer setup` (install complète), `composer dev` (serve + queue + pail + vite
via `concurrently`), `composer test`.

## 2. Front-end

| Brique | Version | Usage |
|---|---|---|
| Blade | (Laravel) | Moteur de templates, composants anonymes `<x-ui.*>` |
| Livewire | **3.8.1** (`^3.5`) | Réactivité serveur, `wire:navigate` (SPA-like) |
| Livewire Volt | 1.10.5 (`^1.10`) | Pages en composants **single-file** routées via `Volt::route()` |
| Alpine.js | 3.15.12 + plugins `focus`, `collapse` | Importé depuis le bundle ESM de Livewire (`livewire.esm`) puis `Livewire.start()` |
| Tailwind CSS | **4.3.1** (`^4.0`) | Config **CSS-first** (`@theme`, `@source`, `@plugin` dans `resources/css/app.css`), pas de `tailwind.config.js` |
| @tailwindcss/forms | 0.5.11 | |
| Vite | **8.0.16** (`^8.0`) | Build, via `laravel-vite-plugin` 3.1.0 + `@tailwindcss/vite` |
| Icônes | `lucide-static` 1.21 (copiées en composants Blade `components/icons/*` via `adb:sync-lucide-icons`) + `codeat3/blade-phosphor-icons` ^2 | Règle « zéro emoji dans l'UI » testée |
| Libs JS métier | `leaflet` 1.9.4 + `leaflet.markercluster`, `intl-tel-input` 29 | Cartes, saisie téléphone |
| Design tokens | Couleurs **OKLCH** dans `@theme`, dark mode par classe `.dark` sur `<html>` | |
| PWA | `public/sw.js` + manifest, Web Push | SW enregistré seulement sous `/espace` |

Points d'entrée Vite : `resources/css/app.css`, `resources/js/app.js` (un fichier JS par fonctionnalité,
importé dans `app.js`).

## 3. Base de données, ORM, migrations, seeders

- **SGBD** : SQLite en local et en CI (`database/database.sqlite`, `:memory:` pour les tests) ;
  **MySQL 8** en production (Hostinger cPanel).
- **ORM** : Eloquent. `doctrine/dbal` ^4 présent (modifications de colonnes).
- **Drivers « sans Redis »** : `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` = `database`.
- **Identifiants** : `id()` auto-incrément **+** colonne `ulid` (char 26, unique) exposée dans les URLs
  (trait `App\Shared\Concerns\HasUlid`). Codes humains via `HasHumanCode`.
- **Soft deletes** sur les entités métier.
- **Machines d'états** : maison (`App\Shared\Concerns\HasStateMachine` + Enums `*Status` dans
  `app/Shared/Enums`), cf. ADR-008. Transitions via des Actions `Transition{Model}`.

### Conventions de migrations
- Fichiers `YYYY_MM_DD_HHMMSS_create_{table}_table.php` / `add_{col}_to_{table}` — classes anonymes,
  `declare(strict_types=1)`, closures typées `function (Blueprint $table): void`.
- **Une migration = un changement atomique** ; jamais modifier une migration déjà déployée.
- FK explicites : `foreignId('x_id')->constrained()->cascadeOnDelete()` / `nullOnDelete()`.
- Index et contraintes uniques déclarés systématiquement (`unique(['master_id','phone'])`).
- Tables snake_case pluriel, booléens `is_*`, dates `*_at`, pivots alphabétiques singuliers.
- `ModulesServiceProvider` sait aussi charger `app/Modules/{Module}/Database/Migrations` (non utilisé
  en pratique : toutes les migrations sont dans `database/migrations`).

### Seeders et factories
- `DatabaseSeeder` n'appelle que les **données de référence idempotentes** (`RoleSeeder`,
  `TradeSeeder`, `CommunitySeeder`), rejouées à chaque déploiement.
- Seeders séparés par usage : `DemoSeeder`, `SampleSeeder`, `ProdSeeder`, lancés via des commandes
  Artisan dédiées (`adb:seed-demo`, …). Données statiques volumineuses dans `database/data/*.php`.
- Une factory par modèle, avec états nommés (`User::factory()->master()`).

## 4. Authentification et autorisation

| Brique | Version | Usage |
|---|---|---|
| **Laravel Breeze** | 2.4.2 (dev) | Scaffolding « Blade + Livewire/Volt » publié puis customisé (`routes/auth.php`, `app/Http/Controllers/Auth/*`, `resources/views/auth/*`). Breeze n'est plus nécessaire après publication. |
| **Sanctum** | 4.3.2 | Sessions web ; `HasApiTokens` prêt pour une future API mobile. |
| **spatie/laravel-permission** | 7.4.2 | Rôles `admin`, `master`, `customer` (`RoleSeeder`), gestion des rôles dans Filament. |
| Filament | 4.11.7 | Panel `/admin`, accès via `FilamentUser::canAccessPanel()`. |
| Vérification email | `MustVerifyEmail` sur `User` | |
| OTP téléphone | Module maison `Auth` (drivers `log` / Termii), désactivable (`OTP_ENABLED`) | |
| Policies | Une policy par modèle, auto-enregistrées depuis `app/Modules/*/Policies` | |
| Rate limiting | Limiteurs nommés (`throttle:short-link`, …) | |

## 5. Packages tiers notables

| Domaine | Package | Version |
|---|---|---|
| Admin back-office | `filament/filament` | 4.11.7 |
| DTO typés | `spatie/laravel-data` | 4.23.0 |
| Médias / uploads | `spatie/laravel-medialibrary` (+ conversions, `media-library:regenerate`) | 11.23.0 |
| Images | `intervention/image` | 3.11.8 |
| Blurhash | `bepsvpt/blurhash` | 4.1.0 |
| Journal d'audit | `spatie/laravel-activitylog` | 4.12.3 |
| Liens signés | `spatie/laravel-url-signer` | 3.3.0 |
| Arborescences | `staudenmeir/laravel-adjacency-list` | 1.26.1 |
| Téléphones | `propaganistas/laravel-phone` | 6.0.3 |
| Sanitization HTML | `mews/purifier` | 3.4.4 |
| Notifications push | `minishlink/web-push` (VAPID, `adb:` commande de génération) | 10.1.0 |
| QR codes | `chillerlan/php-qrcode` ⚠️ **dépendance transitive** utilisée directement | 5.0.5 |
| Notifications | Notifications Laravel (table `notifications`) + Mailables Blade | — |
| **PDF** | ❌ aucun | — |
| **Excel / CSV** | ❌ aucun | — |
| Paiement | Kkiapay prévu (variables `.env`), pas de SDK installé | — |
| Monitoring | Sentry prévu (`SENTRY_LARAVEL_DSN`), **package non installé** | — |

## 6. Outils de qualité

| Outil | Version | Configuration |
|---|---|---|
| **Pest** | 4.7.3 (PHPUnit 12.5) + `pest-plugin-laravel`, `pest-plugin-arch` | `tests/Pest.php` : `RefreshDatabase` + seed des rôles pour `Feature` et `app/Modules` |
| **Laravel Pint** | 1.29.2 | `pint.json` : preset `laravel` + `declare_strict_types`, `ordered_class_elements`, imports globaux, `single_quote`, `void_return`, … |
| **Larastan / PHPStan** | 3.10.0 | `phpstan.neon` **niveau 8** sur `app/`, `config/`, `database/`, `routes/` |
| Laravel Dusk | 8.6.0 | Installé mais **aucun test Browser** présent |
| Playwright | — | Scripts `scripts/*.mjs` (smoke front, debug) ⚠️ **non déclaré dans `package.json`** |
| Collision, Pail, Mockery, Faker | 8.9 / 1.2 / 1.6 / 1.24 | |
| `.editorconfig` | — | LF, UTF-8, 4 espaces (2 pour JS/CSS/YAML/JSON/MD) |
| `.npmrc` | — | `ignore-scripts=true`, `audit=true` |

Documentés mais **absents** du repo : Husky, lint-staged, commitlint, ESLint, Prettier
(+ `prettier-plugin-tailwindcss`), seuil de couverture.

## 7. Gestion de version et CI

- **Git**, remote principal `origin` = GitLab (`gitlab.com/slavesdigital/artisandubenin`), miroir `github`.
- Branches : `main` (prod) ; `feat/{scope}-…`, `fix/{scope}-…`, `hotfix/…` ; squash au merge ; tags SemVer.
- **Conventional Commits** en français (`feat(admin): …`, `fix(…)`, `test(…)`, `chore: …`).
- `CHANGELOG.md` au format Keep a Changelog.
- **GitLab CI** (`.gitlab-ci.yml`) — 4 stages, images `lorisleiva/laravel-docker:8.3` et `node:22-alpine` :
  1. `install` : `composer install` / `npm ci` (cache sur les lockfiles)
  2. `lint` : `pint --test`, `phpstan analyse`
  3. `test` : Pest sur SQLite (`memory_limit=1G`)
  4. `build` : `npm run build` (artefact `public/build/`)
  - **Pas de job de déploiement** (prévu « P0.7+ »).
- **Docker** : aucun (`Dockerfile`, `docker-compose`, Sail absents) ; Docker uniquement via les images CI.

## 8. Déploiement

- **Cible** : Hostinger Premium/Business mutualisé **cPanel** (PHP 8.3, MySQL 8, pas de Node, pas de Redis).
- **Disposition serveur** : repo Laravel **hors webroot** (`~/websites/<repo>/`), webroot cPanel
  contenant un `index.php` bridge + `.htaccess` (sécurité, HTTPS, cache) + symlinks vers
  `public/build`, `public/storage`, `sw.js`, manifest (cf. `docs/11-deploiement/`, ADR-010).
- **Assets** : build Vite en local, **`public/build/` commité** dans Git.
- **Procédure** : `git pull origin main` puis `bash scripts/post-deploy.sh` (idempotent) :
  `composer install --no-dev -o` → `migrate --force` → `storage:link` → seeders de référence →
  `config/route/view/event:cache` → `media-library:regenerate --only-missing` → `queue:restart` →
  `adb:healthcheck`.
- **Crons cPanel** : `schedule:run` chaque minute + `queue:work --stop-when-empty --max-time=50`.
- **Tâches planifiées** (`routes/console.php`) : backup quotidien `adb:backup --keep=7`, digest hebdo.
- **Observabilité** : route `/up`, page Filament `/admin/status`, commande `adb:healthcheck`, Pail en dev.
- **`.env.example`** : `APP_LOCALE=fr`, `APP_TIMEZONE=Africa/Porto-Novo`, `APP_FAKER_LOCALE=fr_FR`,
  `FILESYSTEM_DISK=public`, `MAIL_MAILER=log` en local (SMTP Hostinger en prod), clés VAPID,
  toggles de fonctionnalités (`OTP_ENABLED`, `WHATSAPP_ENABLED`, …).

## 9. Structure des dossiers et conventions de nommage

```
app/
├── Console/Commands/          ← commandes préfixées « adb: » (préfixe projet)
├── Filament/Admin/
│   ├── Pages/  Widgets/
│   └── Resources/{Model}s/    ← {Model}Resource.php + Pages/ + Schemas/{Model}Form.php + Tables/{Model}sTable.php
├── Http/Controllers/          ← rares (auth Breeze, endpoints techniques)
├── Http/Middleware/  Http/Requests/
├── Models/                    ← modèles Eloquent centralisés (+ Observers/)
├── Modules/{Module}/          ← organisation par domaine métier
│   ├── Actions/               ← 1 cas d'usage = 1 classe final, méthode execute()
│   ├── Data/                  ← DTO spatie/laravel-data (*Data)
│   ├── Events/ Listeners/ Exceptions/ Policies/ Queries/ Services/ Mail/
│   ├── Http/Controllers/
│   └── Tests/Feature/ (Unit/) ← tests colocalisés avec le module
├── Providers/                 ← AppServiceProvider, EventServiceProvider, ModulesServiceProvider, Filament/
├── Shared/                    ← Concerns (traits), Enums, Exceptions, Helpers, Support, Validation, ValueObjects
└── View/Components/           ← layouts (AppLayout, GuestLayout)
resources/views/
├── components/ui/*.blade.php  ← design system (<x-ui.button>, <x-ui.card>, …)
├── components/icons/          ← icônes Lucide en Blade
├── layouts/  auth/  emails/  errors/  filament/
└── livewire/{zone}/{ressource}/{index|detail|nouveau}.blade.php   ← pages Volt (zones : app, public)
routes/ web.php · auth.php · console.php
docs/  00-vision … 14-v2, 12-decisions-adr/NNN-titre.md (ADR)
prompts/                       ← bibliothèque de prompts IA
scripts/                       ← post-deploy.sh, smoke tests .mjs
```

**Règles de code** (enforcées par Pint / PHPStan / tests) :
- `declare(strict_types=1);` partout, classes **`final`** par défaut, tout typé (params, retours, propriétés).
- Actions nommées au **verbe impératif** (`CreateCustomer`, `TransitionOrder`) ; DTO `*Data` ;
  événements au participe passé (`CustomerCreated`) ; `*Policy`, `*Exception`, `*Service`, `*Test`.
- Logique métier dans les Actions (transactions `DB::transaction`, émission d'événements), jamais
  dans les vues ni les contrôleurs.
- Vues Blade/Volt en **kebab-case**, **URLs et noms de routes en français** (`/annuaire`,
  `galerie.index`, `/espace/...`), code et tables en anglais, messages utilisateur en français
  (`lang/fr`).
- ADR-012 : pas de `@php(...)` inline sur des expressions contenant des parenthèses.
- Commentaires « pourquoi », référence au lot (`// Lot V2.0-51c — …`), `TODO[#issue]`.

---


## Stack proposée pour l'application `compteurs`

**Principe :** on garde **les mêmes outils** qu'`artisandubenin` (PHP, Laravel, Livewire, Alpine,
Tailwind, Vite, Filament, Sanctum, Spatie, Pest, Pint, Larastan, GitLab CI, Hostinger) et on prend
**la dernière version stable de chacun**. Comme le projet part de zéro, il n'y a aucun code à migrer :
c'est le meilleur moment pour monter de version.

Versions relevées sur Packagist, npm, nodejs.org et php.net le **2026-11-07**.

### Backend

| Outil | artisandubenin | **compteurs** | Pourquoi |
|---|---|---|---|
| PHP | 8.3 (sécurité seulement) | **8.5** (8.5.12) — au minimum **8.4** | Support actif jusqu'à fin 2027, sécurité jusqu'à fin 2029. Plusieurs paquets récents exigent déjà 8.4 (Pest 5, activitylog 5). ⚠️ À vérifier dans hPanel Hostinger : choisir la version la plus haute proposée (8.5, sinon 8.4) et la fixer dans `config.platform.php` |
| Laravel | 13.15 | **13.40** (`^13.40`) | Même version majeure, dernière mineure |
| Sanctum | 4.3 | **4.3** (`^4.3`) | Inchangé |
| spatie/laravel-permission | 7.4 | **8.5** (`^8.5`) | Nouvelle majeure |
| spatie/laravel-data | 4.23 | **4.23** (`^4.23`) | Inchangé |
| spatie/laravel-activitylog | 4.12 | **5.1** (`^5.1`) | Nouvelle majeure (PHP 8.4+) |
| spatie/laravel-medialibrary | 11.23 | **11.23** (`^11.23`), seulement s'il y a des fichiers à téléverser | Inchangé |
| Base de données | SQLite dev / MySQL 8 prod | **Identique** | Même hébergeur |
| Drivers | session/cache/queue `database` | **Identique** | Toujours pas de Redis sur le mutualisé |

### Front-end et admin

| Outil | artisandubenin | **compteurs** | Pourquoi |
|---|---|---|---|
| Livewire | 3.8 | **4.4** (`^4.4`) | Livewire 4 intègre nativement les composants « single-file » : Volt n'est plus nécessaire |
| Volt | 1.10 | **Retiré**, remplacé par les composants single-file de Livewire 4 | Même façon d'écrire les pages (PHP et HTML dans un seul fichier), un package en moins |
| Filament | 4.11 | **5.11** (`^5.11`) | Filament 5 est la version compatible avec Livewire 4. Mêmes concepts (Resources, Schemas, Tables) |
| Alpine.js | 3.15 | **3.x livré par Livewire 4** (+ plugins `focus`, `collapse` 3.17) | Même intégration (`livewire.esm`) |
| Tailwind CSS | 4.3.1 | **4.3.5** + `@tailwindcss/vite` 4.3.5 | Même configuration CSS-first (`@theme`) |
| Vite | 8.0 | **8.3** + `laravel-vite-plugin` **3.2** | Même version majeure |
| Node.js (build seulement) | 22 | **24 LTS** (24.22) | Déjà installé sur votre poste, maintenu jusqu'en avril 2028 |

### Authentification

| artisandubenin | **compteurs** | Pourquoi |
|---|---|---|
| Breeze 2.4 (scaffolding publié) | **Laravel Fortify 1.41** + écrans Livewire 4 maison | Breeze n'évolue plus (dernière version mai 2026) et ne génère pas de Livewire 4. Fortify gère toute la logique (connexion, inscription, mot de passe oublié, vérification email, 2FA) sans imposer de vues : on garde nos propres écrans et nos composants `<x-ui.*>`. C'est la base des starter kits Laravel actuels |
| Sanctum + spatie/permission | **Identique** (versions ci-dessus) | |

### Qualité, tests, CI

| Outil | artisandubenin | **compteurs** | Pourquoi |
|---|---|---|---|
| Pest | 4.7 | **5.3** (`^5.3`) + plugins Laravel et Arch | Dernière majeure (PHP 8.4+) |
| Tests navigateur | Dusk (installé, jamais utilisé) + Playwright non déclaré | **Pest Browser Testing** (s'appuie sur Playwright) | Un seul outil, écrit en Pest comme les autres tests |
| Pint | 1.29 | **1.32**, même `pint.json` | Mêmes règles de style |
| Larastan / PHPStan | 3.10, niveau 8 | **3.14**, niveau 8, même `phpstan.neon` | |
| GitLab CI | 4 étapes, PHP 8.3 / Node 22 | **Mêmes 4 étapes**, images alignées sur PHP 8.5 (ou 8.4) / `node:24-alpine`, **+ une étape `deploy` manuelle** (SSH + `post-deploy.sh`) | Le déploiement prévu dans artisan, enfin en place |
| Déploiement | Hostinger cPanel, `post-deploy.sh` | **Identique** | Même hébergeur et même procédure |

### Ce qui ne change pas du tout

Organisation `app/Modules/{Module}` (Actions, Data, Events, Policies, Tests), `app/Shared`,
conventions de nommage, URLs en français, `declare(strict_types=1)` et classes `final`,
Conventional Commits, ADR dans `docs/`, `.editorconfig`, `.npmrc`, `scripts/post-deploy.sh`,
crons Hostinger.

Seul changement dans l'arborescence : les pages Volt `resources/views/livewire/...` deviennent
des composants single-file Livewire 4 (même emplacement, routés avec `Route::livewire()` au lieu
de `Volt::route()`).

### Uniquement si `compteurs` en a besoin

| Besoin | Package conseillé |
|---|---|
| Export / import Excel ou CSV | Exports/Imports intégrés de **Filament 5** (rien à ajouter) |
| PDF | `spatie/laravel-pdf` 2.14 (mode DomPDF, fonctionne sur le mutualisé sans Chrome) |

### Point à vérifier avant de démarrer

Quelle est la **version PHP la plus haute disponible dans votre hPanel Hostinger** ? Si c'est 8.5,
on part en 8.5 ; si c'est 8.4, tout fonctionne aussi en 8.4. En dessous de 8.4, il faudrait
garder Pest 4 et activitylog 4.
