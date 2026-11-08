<?php

use App\Modules\Metering\Support\Journal;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/*
 * EF-35 / H-58 : journal d'activité du propriétaire connecté — ses propres actions seulement.
 */
new #[Title('Journal d\'activité')] class extends Component
{
    use WithPagination;

    public function render(): mixed
    {
        return $this->view([
            'entries' => Activity::query()
                ->where('log_name', Journal::LOG)
                ->where('causer_type', auth()->user()->getMorphClass())
                ->where('causer_id', auth()->id())
                ->latest('id')
                ->paginate(30),
        ]);
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-sand-900">Journal d'activité</h1>
        <p class="text-sm text-sand-600">Vos saisies, estimations, clôtures, paiements et changements de calcul, du plus récent au plus ancien.</p>
    </div>

    <x-ui.card>
        <ul class="divide-y divide-sand-200">
            @forelse ($entries as $entry)
                <li class="py-2.5" wire:key="entry-{{ $entry->id }}">
                    <p class="text-sm text-sand-900">{{ $entry->description }}</p>
                    <p class="text-xs text-sand-500">{{ $entry->created_at->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}</p>
                </li>
            @empty
                <li class="py-2.5 text-sm text-sand-600">Rien d'enregistré pour l'instant.</li>
            @endforelse
        </ul>
    </x-ui.card>

    {{ $entries->links() }}
</div>
