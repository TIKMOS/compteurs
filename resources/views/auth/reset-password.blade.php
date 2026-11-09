<x-guest-layout title="Réinitialiser le mot de passe">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Nouveau mot de passe</h1>
        <p class="text-sm text-sand-600">
            Choisissez un mot de passe que vous saurez retenir.
        </p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.input
            id="email"
            label="Email"
            type="email"
            name="email"
            :value="old('email', $request->email)"
            :error="$errors->first('email')"
            required
            autofocus
            autocomplete="username"
            inputmode="email"
        />

        <x-ui.input
            id="password"
            label="Nouveau mot de passe"
            type="password"
            name="password"
            :error="$errors->first('password')"
            hint="8 caractères au moins. Une phrase facile à retenir vaut mieux qu'un mot compliqué."
            minlength="8"
            required
            autocomplete="new-password"
        />

        <x-ui.input
            id="password_confirmation"
            label="Confirmer le mot de passe"
            type="password"
            name="password_confirmation"
            :error="$errors->first('password_confirmation')"
            required
            autocomplete="new-password"
        />

        <x-ui.button type="submit" variant="primary" class="w-full">
            Mettre à jour
        </x-ui.button>
    </form>
</x-guest-layout>
