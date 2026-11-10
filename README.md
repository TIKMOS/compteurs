# Compteurs

Application web de **répartition des charges d'eau et d'électricité** pour les propriétaires
au Bénin : relevé des décompteurs de chaque logement, saisie des factures SBEE et SONEB, calcul
de la participation de chaque locataire aux frais d'eau et d'électricité.

- Conception fonctionnelle et technique : [`docs/CONCEPTION.md`](docs/CONCEPTION.md)
- Choix techniques (repris d'artisandubenin) : [`docs/STACK.md`](docs/STACK.md)

## Technologies

PHP 8.4+, Laravel 13, Fortify (authentification), Livewire 4 + Alpine.js, Tailwind CSS 4,
Vite 8, MySQL 8 (SQLite en mémoire pour les tests), Pest 5, Pint.

## Prérequis

| Outil | Version | Sous Windows |
|---|---|---|
| PHP | 8.4 ou plus | Fourni par Laragon |
| Composer | 2.8 ou plus | Fourni par Laragon |
| MySQL | 8 | Fourni par Laragon |
| Node.js | 24 LTS (voir `.nvmrc`) | Pour compiler le CSS et le JS uniquement |

**PHP 8.4 dans Laragon** : clic droit dans Laragon › PHP › Version ›
`php-8.4.26-nts-Win32-vs17-x64`, puis « Arrêter » / « Démarrer tout », et rouvrir le terminal
(`php -v` doit afficher 8.4). La production (Hostinger) est en PHP 8.4 : `composer.json` fixe
`config.platform.php` à 8.4.1 pour que Composer ne choisisse jamais un paquet qui exige plus
récent.

**Node.js dans le terminal Laragon** : Laragon y place son propre Node 18, trop ancien pour
Vite 8. Lancer les commandes `npm` depuis un PowerShell ordinaire (Node 24).

## Installation

Les commandes sont écrites pour **PowerShell** (Windows). Windows PowerShell 5.1 n'accepte pas
`&&` : lancez les commandes une par une, ou séparez-les par `;`.

```powershell
cd compteurs
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Créez ensuite la base MySQL `compteurs` (Laragon : démarrer MySQL, puis « Base de données ») ou
en ligne de commande :

```powershell
mysql -u root -e "CREATE DATABASE compteurs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Puis :

```powershell
php artisan migrate
npm run build
```

## Lancer l'application

```powershell
composer dev
```

Cette commande démarre le serveur (<http://localhost:8000>), la file d'attente, l'affichage des
journaux et Vite en mode développement. Pour le serveur seul : `php artisan serve`.

La page de connexion est sur <http://localhost:8000/login>. En local, les emails (vérification de
l'adresse, mot de passe oublié) ne partent pas : ils sont écrits dans `storage/logs/laravel.log`,
où l'on trouve le lien à ouvrir.

## Administration

Le panneau d'administration est sur <http://localhost:8000/admin> : comptes, maisons,
confirmations de conformité et journal d'activité de tous les propriétaires, en lecture seule
(`docs/CONCEPTION.md`, H-79). Il n'a pas de page de connexion propre : on se connecte sur `/login`
avec un compte administrateur.

Un compte devient administrateur par cette commande (le compte doit déjà exister) :

```powershell
php artisan compteurs:admin adresse@exemple.com            # nommer
php artisan compteurs:admin adresse@exemple.com --retirer  # retirer
```

Un administrateur peut ensuite en nommer d'autres depuis le panneau (Comptes › Nommer
administrateur). En production, lancer la même commande en SSH sur le serveur.

## Tests et qualité

```powershell
php vendor/bin/pest                       # tous les tests
php vendor/bin/pest --testsuite=Modules   # cœur de calcul (app/Modules/Metering)
php vendor/bin/pest --filter=T-08         # un cas de CONCEPTION.md § 5.7
npm run test:js                           # file d'attente hors ligne (Node)
composer lint                             # vérifie le formatage (Pint)
php vendor/bin/pint                       # corrige le formatage
```

Les tests utilisent SQLite en mémoire : MySQL n'a pas besoin d'être démarré.

## Mise en production (Hostinger)

Tout est dans **[docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md)** : installation sur Hostinger (cPanel, SSH),
HTTPS, cron, secrets GitHub, sauvegardes, retour arrière.

En bref : chaque push lance la CI GitHub Actions (`.github/workflows/ci.yml` : Pint, PHPStan, Pest,
tests JS, build Vite). Sur `main`, si tout est vert, elle déploie sur Hostinger par SSH avec
`scripts/deploy.sh` (maintenance, code, dépendances sans dev, assets, sauvegarde, migrations, caches).
Retour à la version précédente : `bash scripts/deploy.sh --retour-arriere` sur le serveur.

## Problème connu : certificat SSL et antivirus Avast

**Symptôme** : `composer install` ou `composer require` échoue avec

```
curl error 60 while downloading https://repo.packagist.org/packages.json:
SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)
```

**Cause** : le « Bouclier Web » d'Avast intercepte les connexions HTTPS et les signe avec son
propre certificat racine (« Avast Web/Mail Shield Root »). Windows lui fait confiance, mais PHP
utilise le fichier de certificats de Laragon (`C:\laragon\etc\ssl\cacert.pem`), qui ne le contient
pas.

**Solution** : créer un fichier de certificats qui ajoute la racine Avast, et le donner à
Composer. Dans PowerShell :

```powershell
$dir = 'C:\laragon\etc\ssl'
$pem = Get-Content "$dir\cacert.pem" -Raw
Get-ChildItem Cert:\LocalMachine\Root, Cert:\CurrentUser\Root |
    Where-Object { $_.Subject -like '*Avast*' } |
    Sort-Object Thumbprint -Unique |
    ForEach-Object {
        $pem += "`n# Avast Web/Mail Shield Root`n-----BEGIN CERTIFICATE-----`n" +
            [Convert]::ToBase64String($_.RawData, 'InsertLineBreaks') +
            "`n-----END CERTIFICATE-----`n"
    }
[IO.File]::WriteAllText("$dir\cacert-avast.pem", $pem)
composer config -g cafile "$dir\cacert-avast.pem"
```

Le fichier d'origine de Laragon n'est pas modifié. Pour annuler : `composer config -g --unset cafile`.
Si Avast renouvelle sa racine, relancer le script. Désactiver l'analyse HTTPS d'Avast règle
aussi le problème, mais réduit la protection du poste.

## Organisation du code

```
app/
├── Actions/Fortify/     ← inscription, mots de passe (logique Fortify)
├── Modules/Metering/    ← cœur de calcul : tranches, estimation, crédit, répartition (sans base de données)
├── Shared/              ← Enums, Fraction (calcul exact), Milli, BeninPhone
└── View/Components/     ← mises en page (GuestLayout, AppLayout)
resources/views/
├── auth/                ← écrans de connexion, inscription, mot de passe (repris d'artisandubenin)
├── components/ui/       ← design system <x-ui.button>, <x-ui.input>, <x-ui.card>
└── layouts/
docs/                    ← CONCEPTION.md, STACK.md
```

Conventions (cf. `docs/STACK.md`) : `declare(strict_types=1)` et classes `final`, code et tables
en anglais, interface et URLs en français, Conventional Commits en français.

Le dossier `public/build/` (CSS et JS compilés) n'est **pas commité** : en local, `npm run build`
(ou `npm run dev`) ; en production, la CI le compile et l'envoie sur le serveur (Hostinger n'a pas Node).
