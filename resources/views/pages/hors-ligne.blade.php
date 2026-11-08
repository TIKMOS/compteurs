{{-- ENF-04 : page affichée par le service worker quand une page n'a jamais été ouverte et
     que le réseau manque. --}}
<x-guest-layout title="Hors connexion">
    <div class="space-y-3 text-center">
        <h1 class="text-xl font-bold text-sand-900">Pas de connexion</h1>
        <p class="text-sm text-sand-600">
            Cette page n'a pas encore été ouverte sur ce téléphone. Les pages déjà consultées restent
            disponibles hors ligne, et les index saisis sans réseau sont envoyés dès son retour.
        </p>
        <x-ui.button as="a" href="/maisons" class="w-full">Réessayer</x-ui.button>
    </div>
</x-guest-layout>
