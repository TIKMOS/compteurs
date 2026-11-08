{{-- EF-38 / UC-19 : espace en lecture seule d'un locataire, ouvert par son lien personnel. --}}
<x-guest-layout title="Mes participations">
    <div class="space-y-5">
        <div>
            <h1 class="text-xl font-bold text-sand-900">Bonjour {{ $tenant->name }}</h1>
            <p class="text-sm text-sand-600">
                Voici le détail de votre participation aux frais d'eau et d'électricité, mois par mois.
                Ce lien vous est personnel : ne le partagez pas.
            </p>
        </div>

        @forelse ($statements as $s)
            <x-statement-card :s="$s" :pdf-url="route('tenant.portal.pdf', [$token, $s->occupancy->id, $s->period->month->format('Y-m')])"
                :photo-url="fn (int $readingId) => route('tenant.portal.photo', [$token, $readingId])" />
        @empty
            <p class="text-sm text-sand-600" role="status">Aucun mois n'est encore clôturé pour votre logement.</p>
        @endforelse
    </div>
</x-guest-layout>
