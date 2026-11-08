<?php

use App\Models\House;
use App\Modules\Metering\Queries\HouseBalances;
use App\Modules\Metering\Queries\HouseDashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Lot 2 : tableau de bord d'une maison — à encaisser, à rembourser, et par mois clôturé :
 * facture, participations, part du propriétaire, écart de comptage (EF-20).
 */
new class extends Component
{
    #[Locked]
    public House $house;

    public function mount(House $house): void
    {
        $this->authorize('view', $house);
        $this->house = $house;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function months(): array
    {
        return app(HouseDashboard::class)->months($this->house);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function balances(): array
    {
        return app(HouseBalances::class)->execute($this->house);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function current(): array
    {
        return app(HouseDashboard::class)->currentMonth($this->house);
    }

    /**
     * @return list<array{type: string, tone: string, message: string, url: string}>
     */
    #[Computed]
    public function alerts(): array
    {
        return app(\App\Modules\Metering\Queries\HouseAlerts::class)->execute($this->house);
    }

    public function render(): mixed
    {
        return $this->view()->title('Tableau de bord · '.$this->house->name);
    }
};
?>

@php
    $fcfa = fn (?int $a): string => $a === null ? '—' : \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Tableau de bord</h1>
    </div>

    @php
        $c = $this->current;
        $b = $this->balances;
        $unpaidAmount = array_sum(array_map(fn ($r) => $r['remaining'], $b['unpaid']));
    @endphp

    {{-- Mois en cours --}}
    <x-ui.card class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-sand-900">{{ \Illuminate\Support\Str::ucfirst($c['month']->locale('fr')->isoFormat('MMMM YYYY')) }}</h2>
            <x-ui.badge :tone="$c['status'] === 'Clôturé' ? 'success' : 'neutral'">{{ $c['status'] }}</x-ui.badge>
        </div>
        <p class="text-sm text-sand-700">
            {{ $c['done'] }}/{{ $c['needed'] }} relevés saisis{{ $c['estimated'] ? ', dont '.$c['estimated'].' estimé'.($c['estimated'] > 1 ? 's' : '') : '' }}
        </p>
        <div class="grid grid-cols-2 gap-2">
            <x-ui.button as="a" href="{{ route('periodes.show', [$house, $c['month']->format('Y-m')]) }}" wire:navigate size="sm">Saisir les relevés</x-ui.button>
            <x-ui.button as="a" href="{{ route('periodes.summary', [$house, $c['month']->format('Y-m')]) }}" wire:navigate variant="outline" size="sm">Voir le calcul</x-ui.button>
        </div>
    </x-ui.card>

    {{-- Encaissements --}}
    <div class="grid grid-cols-2 gap-3">
        @foreach ([
            ['À encaisser', $b['totalRemaining'], $b['totalRemaining'] > 0 ? 'text-warning' : 'text-success'],
            ['Encaissé', app(\App\Modules\Metering\Queries\HouseDashboard::class)->collected($house), 'text-sand-900'],
            ['Impayés ('.count($b['unpaid']).')', $unpaidAmount, count($b['unpaid']) > 0 ? 'text-danger' : 'text-success'],
            ['À rembourser', $b['totalRefunds'], 'text-sand-900'],
        ] as [$label, $amount, $color])
            <a href="{{ route('paiements.index', $house) }}" wire:navigate>
                <x-ui.card class="hover:border-primary-300">
                    <p class="text-xs text-sand-600">{{ $label }}</p>
                    <p class="text-lg font-bold tabular-nums whitespace-nowrap {{ $color }}">{{ $fcfa($amount) }}</p>
                </x-ui.card>
            </a>
        @endforeach
    </div>

    {{-- Alertes --}}
    <x-ui.card class="space-y-2">
        <h2 class="text-base font-semibold text-sand-900">Alertes</h2>
        @forelse ($this->alerts as $alert)
            <a href="{{ $alert['url'] }}" wire:navigate class="flex items-start gap-2 rounded-[var(--radius)] p-2 text-sm hover:bg-sand-50" wire:key="alert-{{ $loop->index }}">
                <x-ui.badge :tone="$alert['tone']" size="sm" class="mt-0.5 shrink-0">
                    {{ ['missing_readings' => 'Relevés', 'estimate_pending' => 'Estimation', 'credit_remaining' => 'Crédit', 'uncapped_fixed_price' => 'Mode 2'][$alert['type']] }}
                </x-ui.badge>
                <span class="text-sand-800">{{ $alert['message'] }}</span>
            </a>
        @empty
            <p class="text-sm text-success" role="status">Rien à signaler.</p>
        @endforelse
    </x-ui.card>

    <div class="flex flex-wrap gap-2">
        <x-ui.button as="a" href="{{ route('maisons.export', $house) }}" variant="outline" size="sm">Exporter l'historique (CSV)</x-ui.button>
    </div>

    @forelse ($this->months as $row)
        <x-ui.card class="space-y-3" wire:key="month-{{ $row['period']->id }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-sand-900">
                    <a href="{{ route('periodes.summary', [$house, $row['period']->month->format('Y-m')]) }}" wire:navigate class="hover:underline">
                        {{ \Illuminate\Support\Str::ucfirst($row['period']->label()) }}
                    </a>
                </h2>
                @if ($row['estimated'] > 0)
                    <x-ui.badge tone="warning" size="sm">{{ $row['estimated'] }} relevé{{ $row['estimated'] > 1 ? 's' : '' }} estimé{{ $row['estimated'] > 1 ? 's' : '' }}</x-ui.badge>
                @endif
            </div>

            @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                @isset($row['utilities'][$utility->value])
                    @php
                        $u = $row['utilities'][$utility->value];
                    @endphp
                    <dl class="text-sm space-y-0.5" wire:key="u-{{ $row['period']->id }}-{{ $utility->value }}">
                        <dt class="font-medium text-sand-900">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }}</dt>
                        <div class="flex justify-between gap-3"><dt class="text-sand-600">Facture</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($u['bill']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-600">Participations des locataires</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($u['tenants']) }}</dd></div>
                        @if ($u['owner'] !== null)
                            <div class="flex justify-between gap-3"><dt class="text-sand-600">Part du propriétaire</dt><dd class="tabular-nums whitespace-nowrap">{{ $u['owner'] < 0 ? '−'.$fcfa(-$u['owner']) : $fcfa($u['owner']) }}</dd></div>
                        @endif
                        @if ($u['gap'] !== null)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sand-600">Écart de comptage</dt>
                                <dd class="tabular-nums whitespace-nowrap {{ abs((float) str_replace(',', '.', $u['gapPct'])) >= 10 ? 'text-warning font-medium' : '' }}">
                                    {{ $qty($u['gap'], $utility) }} ({{ $u['gapPct'] }} %)
                                </dd>
                            </div>
                        @endif
                    </dl>
                @endisset
            @endforeach
        </x-ui.card>
    @empty
        <x-ui.card role="status">
            <p class="text-sm text-sand-600">Aucun mois clôturé pour l'instant.</p>
        </x-ui.card>
    @endforelse

    <p class="text-xs text-sand-500">
        Écart de comptage = consommation du compteur principal − somme des décompteurs, quand elle est saisie sur la facture.
        Un écart important et durable peut signaler une fuite ou un branchement non relevé. Il ne change aucun montant.
    </p>
</div>
