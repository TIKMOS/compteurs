{{-- Lien de partage d'une fiche expiré ou modifié (lien signé valable 7 jours). --}}
<x-guest-layout title="Lien expiré">
    <div class="space-y-3 text-center">
        <h1 class="text-xl font-bold text-sand-900">Ce lien n'est plus valable</h1>
        <p class="text-sm text-sand-600">
            Les liens vers une fiche de participation sont valables {{ \App\Modules\Metering\Data\TenantStatement::SHARE_DAYS }} jours.
            Demandez à votre propriétaire de vous renvoyer la fiche.
        </p>
    </div>
</x-guest-layout>
