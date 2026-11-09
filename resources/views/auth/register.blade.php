<x-guest-layout title="Créer un compte">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Créer mon compte propriétaire</h1>
        <p class="text-sm text-sand-600">
            Gérez vos maisons et calculez la participation de chaque locataire aux frais d'eau et d'électricité.
        </p>
    </div>

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        <x-ui.input
            id="name"
            label="Nom complet"
            name="name"
            :value="old('name')"
            :error="$errors->first('name')"
            required
            autofocus
            autocomplete="name"
        />

        <x-ui.input
            id="email"
            label="Email"
            type="email"
            name="email"
            :value="old('email')"
            :error="$errors->first('email')"
            required
            autocomplete="email"
            inputmode="email"
        />

        <x-ui.input
            id="phone"
            label="Téléphone (facultatif)"
            type="tel"
            name="phone"
            :value="old('phone')"
            :error="$errors->first('phone')"
            hint="Pour vous connecter aussi avec votre numéro, par exemple 01 97 00 00 00."
            autocomplete="tel"
            inputmode="tel"
        />

        <x-ui.input
            id="password"
            label="Mot de passe"
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

        <div class="flex flex-col gap-1">
            <label for="terms" class="flex items-start gap-3 text-sm text-sand-800">
                <input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms')) required
                    class="mt-0.5 h-5 w-5 rounded border-sand-300 text-primary-600 focus:ring-primary-300" />
                <span>
                    J'accepte les
                    <a href="{{ route('legal.terms') }}" target="_blank" class="font-medium text-primary-700 underline">conditions d'utilisation</a>.
                    Je reste seul responsable du respect de mes contrats SBEE et SONEB.
                </span>
            </label>
            @error('terms') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Créer mon compte
        </x-ui.button>

        <p class="text-center text-sm text-sand-600 pt-2">
            Déjà inscrit ?
            <a href="{{ route('login') }}" class="text-primary-700 font-medium hover:underline">Se connecter</a>
        </p>
    </form>
</x-guest-layout>
