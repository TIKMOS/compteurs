<x-app-layout title="Mes maisons">
    <h1 class="text-2xl font-bold text-sand-900">Mes maisons</h1>

    <x-ui.card class="mt-6">
        <p class="text-sand-700">
            Bonjour {{ auth()->user()->name }}, votre compte est prêt.
        </p>
        <p class="mt-2 text-sm text-sand-500">
            La gestion des maisons, des relevés et de la répartition des charges arrive avec le lot 1.
        </p>
    </x-ui.card>
</x-app-layout>
