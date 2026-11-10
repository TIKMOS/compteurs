# Revue avant déploiement

Date : 9 novembre 2026. Base : commit `c0a96de` (253 tests), données de démonstration
(1 maison, 4 logements, 4 locataires, août à octobre clôturés).

Classement :
- **Bloquant** : interdit la mise en production.
- **Important** : à corriger avant la mise en production.
- **Mineur** : à planifier, ne bloque pas.

## Synthèse

| Point | Résultat | Bloquant | Important | Mineur |
|---|---|---|---|---|
| 1. Sécurité | Cloisonnement entre propriétaires sans faille sur 24 URL et 9 actions Livewire testées. CSRF : Laravel 13 vérifie aussi l'origine (`Sec-Fetch-Site`) | 0 | 1 | 3 |
| 2. Exactitude du calcul | 257 tests au vert ; couverture du module Metering : **97,7 %** des lignes | 0 | 1 | 1 |
| 3. Qualité | PHPStan niveau 5 : 41 erreurs ; `composer lint` ne lance pas PHPStan | 0 | 1 | 1 |
| 4. Performance | Index corrects ; requêtes N+1 bornées par le nombre de locataires ; PDF en 0,9 à 1,4 s | 0 | 0 | 3 |
| 5. Ergonomie mobile | 22 écrans à 360 px, aucun débordement horizontal | 0 | 0 | 3 |
| 6. Configuration de production | Pages d'erreur en anglais, pas de configuration de production décrite | 0 | 2 | 0 |
| 7. Conformité | « vente » / « achat » absents ; **« facture » présent côté locataire** ; avertissement du mode 2 bien enregistré | 0 | 1 | 0 |

**Aucun problème bloquant.** 7 importants, 11 mineurs.

---

## 1. Sécurité

### Ce qui a été vérifié

- **Cloisonnement** : nouveau test `Tests/Feature/OwnerIsolationTest.php`. Un second propriétaire, avec sa
  propre maison, essaie d'ouvrir les données du compte de démonstration :
  - 24 URL : toutes les pages de la maison, tous les PDF, les exports CSV et Excel, les photos, la fiche
    locataire. Il essaie aussi sa propre maison avec l'identifiant d'un logement, d'une occupation ou d'un
    relevé de l'autre compte. Résultat : **404 partout** (`denyAsNotFound` : l'existence n'est même pas révélée).
  - 9 actions Livewire appelées avec des identifiants de l'autre compte : encaisser, annuler un paiement,
    marquer un remboursement, saisir, estimer, remplacer, retirer une photo, entrée avec le locataire d'un
    autre. Résultat : toutes refusées (404 ou erreur de validation), et rien n'est écrit en base.
  - Les propriétés `#[Locked]` empêchent de remplacer la maison d'un composant.
  - Listes (maisons, locataires, journal) : on ne voit que ses propres données.
- **Liens locataire** : un jeton n'ouvre que les fiches de son propre locataire. Un faux jeton donne 404.
  Le lien signé WhatsApp donne 403 si on le modifie, par exemple en changeant le numéro d'occupation.
- **CSRF** : `POST /login` sans jeton → 419 (vérifié sur le serveur, hors tests). Livewire vérifie le jeton
  et la somme de contrôle de chaque composant.
- **Validation côté serveur** : tous les formulaires valident côté serveur (longueurs maximales, dates,
  listes fermées, montants positifs, trop-perçu refusé). Une exception est notée en I-7.
- **Injection** : aucune requête SQL brute (`DB::raw`, `whereRaw`…) et aucun affichage non échappé (`{!! !!}`).
  Les photos sont sur un disque privé, servies après contrôle d'accès.
- **Connexion** : 5 essais par minute pour un même identifiant et une même adresse IP, puis 429.
  L'email doit être vérifié pour accéder aux données, et changer d'email oblige à le vérifier de nouveau.
  Nouveau test : `tests/Feature/AuthSecurityTest.php`.

### Problèmes

| # | Gravité | Problème | Correction |
|---|---|---|---|
| I-7 | Important | `periodes/saisie` : le numéro de série du nouveau décompteur (remplacement) n'est pas validé. Au-delà de 60 caractères, MySQL refuse l'écriture → erreur 500. | Ajouter `max:60` à la validation. |
| M-1 | Mineur | Mots de passe : 8 caractères au minimum, sans autre règle (`Password::default()` jamais configuré). | Plus tard : `Password::defaults()` avec `uncompromised()` en production (consulte le service « Have I Been Pwned »). |
| M-2 | Mineur | La clé de limitation des tentatives prend le texte saisi tel quel. Un même numéro écrit de plusieurs façons (`01 97…`, `+229 01 97…`) compte comme plusieurs identifiants. L'adresse IP reste dans la clé : l'effet est limité. | Normaliser le numéro (`BeninPhone::normalize`) dans la clé. |
| M-3 | Mineur | Aucun test ne couvrait la limitation des tentatives ni l'obligation de vérifier l'email. | **Fait pendant la revue** : `AuthSecurityTest`. |

## 2. Exactitude du calcul

- Suite complète : **257 tests au vert** (253 + 4 tests de cloisonnement).
- Couverture (PCOV) du module `app/Modules/Metering` : **97,7 % des lignes** (2 331 / 2 387). Objectif de
  90 % atteint. Services de calcul : 97,8 % ; requêtes : 97,3 % ; actions : 97,2 %.
- Les lignes non couvertes sont surtout des gardes défensives (consommation négative, lot de crédit nul).
  Restent quatre cas réels :
  - un fluide sans aucun décompteur dans la maison ;
  - les messages « facture manquante » et « consommation nulle » au moment de la clôture ;
  - la correction d'un index de fin de mois au-dessus du relevé du mois suivant ;
  - un mois précédent sans prix pour valoriser une estimation.
- Cas limites demandés, déjà couverts : modes 1 et 2 (T-36 à T-45), plafond (T-36, T-37, T-38), estimation
  (T-10 à T-12, T-25), crédit (T-20 à T-24, T-31, T-34), sortie avec remboursement (T-30, T-35), logement
  vacant (T-04, T-06, T-27, T-44), arrondi au franc (T-01, T-28, T-32, T-45).

| # | Gravité | Problème | Correction |
|---|---|---|---|
| I-3 | Important | **Dépassement d'entier** dans le mode 2 avec des valeurs très grandes (fautes de frappe). Exemple : facture de 99 999 999 FCFA avec des index d'environ 10 millions de kWh. Le produit prix × consommation dépasse les entiers 64 bits : PHP passe en flottant, puis `TypeError`. Résultat : erreur 500 sur la saisie et le résultat du mois, au lieu d'un message qui montre l'index fautif. Le mode 1 tient jusqu'à ces valeurs ; les valeurs réalistes (≤ 20 millions de FCFA, ≤ 100 000 kWh) passent dans les deux modes. | Contrôler les multiplications dans `Fraction`, `Milli` et le calcul, et lever une erreur métier traduite en message clair (« valeurs trop grandes, vérifiez les index et le montant »). Test de régression. |
| M-4 | Mineur | Les quatre cas réels listés plus haut ne sont pas testés. | Ajouter ces tests (cas limites de la revue). |

## 3. Qualité

- Larastan 3.14 installé (`larastan/larastan ^3.0`, en développement).
- **PHPStan niveau 5 : 41 erreurs**, presque toutes de typage, sans bug à l'exécution :
  - relations Eloquent sans type générique (`HasMany<BillingPeriod>`), donc `Model` au lieu de
    `BillingPeriod` ;
  - `@property` en `float` pour des colonnes `decimal` (des chaînes à l'exécution) ;
  - `DateTimeImmutable` au lieu de `CarbonImmutable` dans des DTO ;
  - types de retour de tableaux incomplets ;
  - opérateurs `?->` inutiles.
- Niveau 8, demandé par ENF-14 : 183 erreurs, surtout l'accès à des relations qui peuvent être nulles.

| # | Gravité | Problème | Correction |
|---|---|---|---|
| I-6 | Important | PHPStan n'est pas configuré (`phpstan.neon` absent) et `composer lint` ne lance que Pint. Le niveau 5 signale 41 erreurs. | `phpstan.neon` au niveau 5, corriger les 41 erreurs, ajouter `phpstan analyse` à `composer lint`. |
| M-5 | Mineur | Niveau 8 (ENF-14) non atteint : 183 erreurs. | Tranche dédiée, en relevant le niveau pas à pas. |

## 4. Performance

Mesures sur SQLite avec les données de démonstration (4 locataires) :

| Écran | Requêtes | Temps |
|---|---|---|
| Liste des maisons, fiche d'une maison, paramètres, logement | 2 à 10 | < 100 ms |
| Saisie d'un mois | 16-17 | 55-130 ms |
| Tableau de bord | 32 | 120 ms |
| Résultat d'un mois clôturé | **99** | 150-225 ms |
| Export Excel | 19 | 400 ms |
| Fiche PDF d'un locataire | 20 | **1,4 s** (premier PDF, chargement des polices) |
| Récapitulatif PDF | 23 | 0,9 s |

- **Index** : toutes les clés étrangères utiles sont indexées. Les recherches fréquentes ont un index
  composé : relevés (`sub_meter_id`, `read_on`), paiements (`occupancy_id`, `billing_period_id`),
  crédits, participations.

| # | Gravité | Problème | Correction |
|---|---|---|---|
| M-6 | Mineur | Résultat d'un mois clôturé : environ 22 requêtes par locataire. Chaque fiche est reconstruite, avec une requête par participation et par somme de paiements. Pour 20 logements, environ 450 requêtes, soit 0,3 à 0,5 s sur MySQL mutualisé. L'export Excel a le même défaut : une somme de paiements par mois et par locataire. | Charger en une fois les participations et les paiements de la période (`whereIn` + regroupement). |
| M-7 | Mineur | Saisie et tableau de bord : une requête de relevés par décompteur (7 pour la démo). | Charger les relevés d'avance (`with`). |
| M-8 | Mineur | PDF : 0,9 à 1,4 s en local. Sur Hostinger, ce sera probablement 2 à 3 s. Acceptable pour un document à la demande. | Mesurer après déploiement. |

## 5. Ergonomie mobile

22 écrans capturés à 360 px dans Edge sans interface, connexion « 3G lente » simulée (400 ms de latence,
400 kbit/s) :
- aucun débordement horizontal ;
- chargement d'une page en 0,8 à 1,4 s une fois les fichiers en cache (service worker) ;
- première visite : CSS 14 Ko + JS 103 Ko compressés, soit environ 3 à 4 s si le serveur compresse.

| # | Gravité | Problème | Correction |
|---|---|---|---|
| M-9 | Mineur | Tableau de bord : à 360 px, les champs « Du mois / Au mois » de l'export coupent l'année (« décembre 20… »). | Mettre les deux champs l'un sous l'autre sur petit écran. |
| M-10 | Mineur | Saisie : les flèches mois précédent / suivant sont petites et pâles. Le lien « Décompteur changé ou remis à zéro ? » est une zone tactile de moins de 32 px de haut. | Agrandir les zones tactiles (44 px). |
| M-11 | Mineur | Paiements : la liste « Impayés » reprend le détail par locataire juste en dessous, ce qui donne une page très longue sur téléphone. | Replier la liste des impayés, ou la limiter aux 5 premiers. |

## 6. Configuration de production

| # | Gravité | Problème | Correction |
|---|---|---|---|
| I-1 | Important | **Pas de pages d'erreur personnalisées** : 404, 403, 419 (page expirée, fréquente sur téléphone), 429 (trop de tentatives de connexion), 500 et 503 affichent la page anglaise de Laravel (« Not Found », « Page Expired »…). | Pages `resources/views/errors/*.blade.php` en français, aux couleurs de l'application, avec un lien de retour. |
| I-2 | Important | **Configuration de production non décrite.** Le seul modèle, `.env.example`, contient `APP_DEBUG=true`, `APP_ENV=local`, `LOG_LEVEL=debug`, un journal unique qui grossit sans limite (`LOG_STACK=single`), aucun `SESSION_SECURE_COOKIE` et `MAIL_MAILER=log`. Le README n'a pas de section de mise en production (cache de configuration, cron `schedule:run`, migrations). | Ajouter `.env.production.example` (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, cookies sécurisés, SMTP) et une section « Mise en production » au README. Test qui vérifie le modèle. |

Déjà en place : langue `fr` et fuseau `Africa/Porto-Novo`, messages de validation et d'authentification
en français, page « lien expiré », page hors connexion.

## 7. Conformité

- « vente », « achat » et leurs variantes : absents de toutes les vues et traductions (test T-54 existant).
  Seules les conditions d'utilisation et l'avertissement du mode 2 les citent, pour interdire la revente.
- **Avertissement du mode 2** : enregistré à chaque activation du prix fixé ou retrait du plafond. On garde
  le compte, la maison, le fluide, le prix, le texte exact affiché, la date et l'adresse IP. C'est testé
  (HousesTest, UtilityPricingTest).
- Recherche de « facture » dans les textes réellement affichés au locataire : portail, fiches PDF et
  message WhatsApp, pour les 4 locataires et les 3 mois de la démo.

| # | Gravité | Problème | Correction |
|---|---|---|---|
| I-4 | Important | **« facture » apparaît côté locataire** : 20 fois sur le portail, 31 fois dans les fiches PDF. Le libellé du prix en mode 1 est « Prix unitaire : … — répartition de la facture ». Les fiches PDF (mensuelle et de sortie) se terminent par « Ce document n'est pas une facture. » | Libellé locataire : « répartition des frais du compteur principal ». Pied des fiches : « Décompte de répartition des charges entre occupants. » Le propriétaire garde « facture SBEE / SONEB » sur ses écrans. Test : aucun texte locataire ne contient « vente », « achat » ou « facture ». |

---

## Suivi des corrections

Les corrections ont été faites dans cet ordre. La suite complète a été relancée après chacune.

| # | Statut | Ce qui a été fait | Tests après correction |
|---|---|---|---|
| I-4 | Corrigé | `ResolvedPrice::label(..., forTenant: true)` : « répartition des frais du compteur principal » sur les fiches, le portail et le message WhatsApp ; le propriétaire garde « répartition de la facture ». Pied des fiches PDF : « Décompte de répartition des charges entre occupants. » Nouveau test dans `VocabularyTest` : tous les textes réellement affichés au locataire sont parcourus (portail, fiches, WhatsApp, lien expiré). | 266 ✔ (sonde I-3 seule en échec, attendu) |
| I-3 | Corrigé | `App\Shared\Support\SafeInt` (multiplication et addition contrôlées) utilisé dans `Fraction`, `Milli`, `AllocationCalculator`, `UnitPriceResolver` et `ResolvedPrice`. `ValueTooLargeException` devient un problème du mois (« Valeurs trop grandes pour être calculées : vérifiez les index saisis et le montant de la facture (un zéro de trop ?) ») qui bloque la clôture. Tests : `tests/Unit/SafeIntTest.php` et `EdgeCasesTest`. Sans le contrôle, le test d'écran échoue bien en `TypeError`. | 269 ✔ |
| M-4 | Corrigé | `EdgeCasesTest` : fluide sans décompteur (H-53), consommation nulle au niveau du mois (T-13), valeurs démesurées et valeurs hautes réalistes (modes 1 et 2), correction au-dessus du relevé du mois suivant (EF-17). | 269 ✔ |
| I-7 | Corrigé | `newSerial` validé (`max:60`), erreur affichée sous le champ, `maxlength` sur l'input. | 270 ✔ |
| I-1 | Corrigé | `resources/views/errors/` : 403, 404, 419, 429, 500, 503, plus des pages génériques 4xx et 5xx. Mise en page autonome (styles en ligne, sans Vite ni base de données), boutons de 44 px. Session expirée pendant une saisie : question en français au lieu de celle de Livewire (`resources/js/app.js`). Test : `ErrorPagesTest`. | 278 ✔, tests JS 5 ✔ |
| I-2 | Corrigé | `.env.production.example`, `scripts/post-deploy.sh` (refuse `APP_DEBUG=true`, migrations, caches), section « Mise en production » du README (cron `schedule:run`). Les caches de configuration, de routes et de vues se construisent. Test : `ProductionConfigTest`. | 281 ✔ |
| I-6 | Corrigé | `phpstan.neon` niveau 5, **0 erreur**. Types génériques et `@property` complétés, `?->` inutiles retirés, comparaisons de dates natives au lieu de méthodes Carbon sur des `DateTimeImmutable`. Une seule exclusion, justifiée : les traits utilisés par les composants Livewire écrits dans les vues. `composer lint` lance Pint puis PHPStan. | 285 ✔ |

**État final** : 285 tests au vert (3 981 assertions), 5 tests JS au vert, `composer lint` au vert.
Couverture du module Metering : **98,3 %** des lignes (97,7 % avant).

Reste à faire (mineurs, non corrigés) : M-1, M-2, M-5 à M-11.

Point non vérifié dans un navigateur : la question en français en cas de session expirée.
Laravel 13 accepte les requêtes Livewire venant du même site grâce à l'en-tête `Sec-Fetch-Site` que le
navigateur ajoute : un 419 n'arrive donc qu'avec un navigateur ancien qui ne l'envoie pas, et Edge
n'a pas permis de le simuler. Le code suit l'API `Livewire.interceptRequest` de Livewire 4.

## Suivi des mineurs (10 novembre 2026)

| # | Statut | Ce qui a été fait |
|---|---|---|
| M-1 | Corrigé | `Password::defaults()` : 8 caractères au moins, sans règle de composition (NIST 800-63B) ; en production, refus des mots de passe divulgués (« Have I Been Pwned », k-anonymat). Règle affichée à l'inscription et au changement de mot de passe. Tests dans `AuthSecurityTest`. |
| M-2 | Corrigé | Clé de limitation : numéro normalisé (`BeninPhone::normalize`) ; + 20 essais par minute par adresse IP, tous comptes confondus. Tests dans `AuthSecurityTest`. |
| M-3 | Corrigé | (pendant la revue) |
| M-4 | Corrigé | (pendant la revue) |
| M-5 | Corrigé | PHPStan **niveau 8 sans erreur** (159 erreurs au départ), réglé dans `phpstan.neon` et vérifié par `composer lint` et la CI. Relations obligatoires typées non nulles ; relations vers la maison et le logement en `withTrashed()` (le vrai parent, même archivé), avec filtre explicite là où les archivés doivent être ignorés (portail locataire, relevés manquants, alertes, saisie) — `ArchivedParentsTest`. Valeurs qui ne peuvent pas manquer (compteur principal, tarification, prix réel en mode 1) : exception explicite au lieu d'un accès sur null. |
| M-6 | Corrigé | `BuildTenantStatement::executeMany()` (fiches d'un mois en une fois), crédits groupés dans `PeriodSummary`, paiements groupés dans l'export Excel. Résultat du mois : 99 → 35 requêtes ; export Excel : 19 → 9. |
| M-7 | Corrigé | `SubMeter::readingChain(fromLoaded: true)` dans les affichages en lecture seule (les actions relisent toujours la base). Saisie : 16 → 9 requêtes ; tableau de bord : 32 → 25. `QueryCountTest` interdit toute requête répétée plus de deux fois sur ces pages. |
| M-8 | À mesurer | Temps des PDF à mesurer sur Hostinger après la mise en ligne (rien à corriger en local). |
| M-9 | Corrigé | Champs de l'export l'un sous l'autre sous 400 px. |
| M-10 | Corrigé | Flèches de mois et lien de remplacement : zones tactiles de 44 px. |
| M-11 | Corrigé | 5 impayés affichés, les autres repliés (« Voir les 6 autres impayés »), sans JavaScript. Tests dans `MobileLayoutTest`. |
