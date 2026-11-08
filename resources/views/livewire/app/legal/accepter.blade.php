<?php

use App\Actions\AcceptTerms;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * EF-51 / H-36 : acceptation des conditions d'utilisation en vigueur, demandée avant tout accès
 * aux comptes créés avant leur publication ou à chaque nouvelle version.
 */
new #[Title('Conditions d\'utilisation')] class extends Component
{
    public bool $accepted = false;

    public function mount(): void
    {
        if (AcceptTerms::isUpToDate(auth()->user())) {
            $this->redirectRoute('maisons.index', navigate: true);
        }
    }

    public function accept(): void
    {
        $this->validate(
            ['accepted' => ['accepted']],
            ['accepted.accepted' => 'Cochez la case pour continuer.'],
        );

        app(AcceptTerms::class)->execute(auth()->user());

        $this->redirectIntended(route('maisons.index'), navigate: true);
    }
};
?>

<div class="space-y-6">
    <h1 class="text-2xl font-bold text-sand-900">Conditions d'utilisation</h1>

    <x-ui.card class="space-y-4">
        <p class="text-sm text-sand-700">
            Pour continuer, merci de lire et d'accepter les conditions d'utilisation en vigueur
            (version du {{ \Carbon\CarbonImmutable::parse(config('legal.terms_version'))->format('d/m/Y') }}).
        </p>
        <a href="{{ route('legal.terms') }}" target="_blank" class="inline-block text-sm font-medium text-primary-700 underline">
            Lire les conditions d'utilisation
        </a>

        <form wire:submit="accept" class="space-y-4">
            <label class="flex items-start gap-3 text-sm text-sand-800">
                <input type="checkbox" wire:model="accepted" class="mt-0.5 h-5 w-5 rounded border-sand-300 text-primary-600 focus:ring-primary-300" />
                <span>J'accepte les conditions d'utilisation. Je reste seul responsable du respect de mes contrats SBEE et SONEB.</span>
            </label>
            @error('accepted') <p class="text-xs text-danger">{{ $message }}</p> @enderror

            <x-ui.button type="submit" class="w-full sm:w-auto">Continuer</x-ui.button>
        </form>
    </x-ui.card>
</div>
