{{-- EF-51 : conditions d'utilisation, page publique. Toute modification du texte impose de
     changer config('legal.terms_version') : les propriétaires l'accepteront de nouveau (H-36).
     Seule page, avec l'avertissement de config/legal.php, autorisée à citer la revente (ENF-15). --}}
<x-guest-layout title="Conditions d'utilisation">
    <article class="space-y-5 text-sm text-sand-800 leading-relaxed">
        <header class="space-y-1">
            <h1 class="text-xl font-bold text-sand-900">Conditions d'utilisation</h1>
            <p class="text-xs text-sand-500">Version du {{ \Carbon\CarbonImmutable::parse(config('legal.terms_version'))->format('d/m/Y') }}</p>
        </header>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">1. Objet</h2>
            <p>
                {{ config('app.name') }} est un outil de <strong>répartition des charges</strong> d'eau et d'électricité.
                À partir des relevés de décompteurs et des factures que le propriétaire saisit, il calcule la
                participation de chaque locataire aux frais d'eau et d'électricité de la maison.
            </p>
            <p>
                <strong>Il s'agit d'une répartition des charges, pas d'une vente</strong> : l'application ne vend ni
                n'achète d'eau ou d'électricité. Elle répartit entre les occupants le coût des factures de la maison,
                ou calcule une participation selon le prix fixé par le propriétaire.
            </p>
            <p>
                L'application ne fournit ni eau ni électricité. Elle n'est pas partie aux contrats d'abonnement
                conclus avec la SBEE ou la SONEB, ni aux contrats de bail entre le propriétaire et ses locataires.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">2. Responsabilité du propriétaire envers la SBEE et la SONEB</h2>
            <p>
                Les règlements de service de la SBEE (art. 90) et de la SONEB interdisent à l'abonné de revendre ou
                céder l'eau et l'électricité à des tiers.
                <strong>Le propriétaire reste seul responsable du respect de ses contrats SBEE et SONEB</strong>
                et de la réglementation qui s'applique à lui, quel que soit le mode de calcul qu'il choisit.
            </p>
            <p>
                Lorsque le propriétaire choisit un prix fixé par lui-même (mode 2), ou retire le plafond au prix
                réel, un avertissement lui est présenté ; sa confirmation est enregistrée avec la date et son nom.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">3. Exactitude des montants</h2>
            <p>
                Les calculs sont exacts au franc près sur la base des données saisies. Le propriétaire est responsable
                des relevés, des montants de factures et des dates qu'il saisit, et vérifie le récapitulatif avant de
                le communiquer à ses locataires. Les relevés estimés sont signalés comme tels et régularisés au relevé
                réel suivant.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">4. Données des locataires</h2>
            <p>
                Le propriétaire ne saisit que les informations nécessaires au calcul (nom, téléphone facultatif,
                dates d'entrée et de sortie, relevés). Il informe ses locataires de cette utilisation et respecte la
                législation béninoise sur la protection des données personnelles.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">5. Compte et traçabilité</h2>
            <p>
                Le propriétaire protège l'accès à son compte. Les saisies, estimations, changements de mode de calcul
                et clôtures de mois sont enregistrés dans un journal d'activité ; les mois clôturés ne sont plus
                modifiables.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold text-sand-900">6. Évolution des conditions</h2>
            <p>
                Ces conditions peuvent évoluer. Toute nouvelle version doit être acceptée par le propriétaire à sa
                connexion suivante pour continuer à utiliser l'application.
            </p>
        </section>

        <p class="pt-2">
            <a href="{{ url()->previous() === url()->current() ? route('login') : url()->previous() }}" class="font-medium text-primary-700 hover:underline">← Retour</a>
        </p>
    </article>
</x-guest-layout>
