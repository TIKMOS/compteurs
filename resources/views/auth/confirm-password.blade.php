<x-guest-layout title="Confirmer le mot de passe">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Confirmer votre identité</h1>
        <p class="text-sm text-sand-600">
            Pour des raisons de sécurité, merci de saisir à nouveau votre mot de passe.
        </p>
    </div>

    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4">
        @csrf

        <x-ui.input
            id="password"
            label="Mot de passe"
            type="password"
            name="password"
            :error="$errors->first('password')"
            required
            autocomplete="current-password"
            autofocus
        />

        <x-ui.button type="submit" variant="primary" class="w-full">
            Confirmer
        </x-ui.button>
    </form>
</x-guest-layout>
