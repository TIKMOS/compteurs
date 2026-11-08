<x-guest-layout title="Connexion">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Bon retour parmi nous</h1>
        <p class="text-sm text-sand-600">
            Connectez-vous pour saisir vos relevés et répartir les charges de vos maisons.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-[var(--radius)] bg-success/10 border border-success/30 px-3 py-2 text-sm text-success">
            {{ session('status') }}
        </div>
    @endif

    @php
        // Même contrat qu'artisandubenin : l'onglet réaffiché est celui qui a été soumis.
        $initialMode = in_array(old('mode'), ['email', 'phone'], true) ? old('mode') : 'email';
    @endphp

    <form
        method="POST"
        action="{{ route('login.store') }}"
        class="space-y-4"
        x-data="{ mode: @js($initialMode) }"
    >
        @csrf

        {{-- Les deux onglets envoient le même champ « login » : le serveur reconnaît un email
             (présence de @) ou un numéro béninois (FortifyServiceProvider). --}}
        <input type="hidden" name="mode" :value="mode" value="{{ $initialMode }}">

        <div class="grid grid-cols-2 gap-1 p-1 rounded-full bg-sand-100" role="tablist" aria-label="Type d'identifiant">
            <button
                type="button"
                role="tab"
                :aria-selected="mode === 'email'"
                @click="mode = 'email'"
                :class="mode === 'email' ? 'bg-white text-sand-900 shadow-sm' : 'text-sand-500'"
                class="h-9 rounded-full text-sm font-medium transition-colors"
                data-testid="login-mode-email"
            >
                Email
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="mode === 'phone'"
                @click="mode = 'phone'"
                :class="mode === 'phone' ? 'bg-white text-sand-900 shadow-sm' : 'text-sand-500'"
                class="h-9 rounded-full text-sm font-medium transition-colors"
                data-testid="login-mode-phone"
            >
                Téléphone
            </button>
        </div>

        {{-- x-if (et non x-show) : le champ inactif est retiré du DOM, donc jamais soumis. --}}
        <template x-if="mode === 'email'">
            <div>
                <x-ui.input
                    id="login-email"
                    label="Email"
                    type="email"
                    name="login"
                    :value="old('login')"
                    :error="$errors->first('login')"
                    autocomplete="username"
                    inputmode="email"
                    required
                />
            </div>
        </template>

        <template x-if="mode === 'phone'">
            <div>
                <x-ui.input
                    id="login-phone"
                    label="Téléphone"
                    type="tel"
                    name="login"
                    :value="old('login')"
                    :error="$errors->first('login')"
                    hint="Numéro béninois, par exemple 01 97 00 00 00."
                    autocomplete="tel"
                    inputmode="tel"
                    required
                />
            </div>
        </template>

        <x-ui.input
            id="password"
            label="Mot de passe"
            type="password"
            name="password"
            :error="$errors->first('password')"
            required
            autocomplete="current-password"
        />

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-sand-700">
                <input
                    id="remember_me"
                    type="checkbox"
                    name="remember"
                    class="h-4 w-4 rounded border-sand-300 text-primary-600 focus:ring-primary-300"
                />
                Se souvenir de moi
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm text-primary-700 hover:underline" href="{{ route('password.request') }}">
                    Mot de passe oublié&nbsp;?
                </a>
            @endif
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Se connecter
        </x-ui.button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-sand-600 pt-2">
                Pas encore de compte ?
                <a href="{{ route('register') }}" class="text-primary-700 font-medium hover:underline">Créer mon compte</a>
            </p>
        @endif
    </form>
</x-guest-layout>
