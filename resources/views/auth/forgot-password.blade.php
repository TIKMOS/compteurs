<x-guest-layout title="Mot de passe oublié">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Mot de passe oublié ?</h1>
        <p class="text-sm text-sand-600">
            Entrez votre email : nous vous enverrons un lien pour choisir un nouveau mot de passe.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-[var(--radius)] bg-success/10 border border-success/30 px-3 py-2 text-sm text-success">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.input
            id="email"
            label="Email"
            type="email"
            name="email"
            :value="old('email')"
            :error="$errors->first('email')"
            required
            autofocus
            autocomplete="username"
            inputmode="email"
        />

        <x-ui.button type="submit" variant="primary" class="w-full">
            Recevoir le lien
        </x-ui.button>

        <p class="text-center text-sm text-sand-600 pt-2">
            <a href="{{ route('login') }}" class="text-primary-700 font-medium hover:underline">← Retour à la connexion</a>
        </p>
    </form>
</x-guest-layout>
