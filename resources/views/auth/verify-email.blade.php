<x-guest-layout title="Vérification de l'email">
    <div class="space-y-1 mb-6">
        <h1 class="text-xl font-bold text-sand-900">Vérifiez votre email</h1>
        <p class="text-sm text-sand-600">
            Merci pour votre inscription ! Pour activer votre compte, cliquez sur le lien
            que nous venons de vous envoyer par email.
        </p>
    </div>

    @if (session('status') === 'verification-link-sent')
        <div class="mb-4 rounded-[var(--radius)] bg-success/10 border border-success/30 px-3 py-2 text-sm text-success">
            Un nouveau lien de vérification a été envoyé à votre adresse email.
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" variant="primary" class="w-full">
                Renvoyer le lien de vérification
            </x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="block w-full text-center text-sm text-sand-600 hover:text-sand-900 hover:underline">
                Se déconnecter
            </button>
        </form>
    </div>
</x-guest-layout>
