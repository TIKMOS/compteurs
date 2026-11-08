<?php

use App\Models\BillingPeriod;
use App\Models\House;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\ReopenBillingPeriod;
use App\Modules\Metering\Exceptions\ReopenRefusedException;
use App\Modules\Metering\Exceptions\IncompletePeriodException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Livewire\PeriodSummary;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-13, UC-14 / EF-30 à EF-32, EF-36, EF-42, EF-43 : résultat du mois — prix appliqué,
 * participation de chaque payeur, récapitulatif par locataire — et clôture.
 */
new class extends Component
{
    #[Locked]
    public House $house;

    #[Locked]
    public string $mois;

    /** @var list<string> */
    public array $closeErrors = [];

    public bool $reopening = false;

    public string $reopenReason = '';

    public function mount(House $house, string $mois): void
    {
        $this->authorize('view', $house);
        $this->house = $house;

        $month = CarbonImmutable::createFromFormat('!Y-m', $mois);
        abort_if($month === false || $month->format('Y-m') !== $mois || $month->isAfter(CarbonImmutable::now()->startOfMonth()), 404);
        $this->mois = $mois;
    }

    #[Computed]
    public function period(): ?BillingPeriod
    {
        return $this->house->billingPeriods()->where('month', $this->mois.'-01')->first();
    }

    #[Computed]
    public function summary(): ?PeriodSummary
    {
        $period = $this->period;
        if ($period === null) {
            return null;
        }

        return $period->isClosed()
            ? PeriodSummary::fromClosed($period)
            : PeriodSummary::fromComputation(app(ComputeBillingPeriod::class)->execute($period));
    }

    public function close(): void
    {
        $this->authorize('update', $this->house);
        $period = $this->period ?? abort(404);

        try {
            app(CloseBillingPeriod::class)->execute($period, auth()->user());
        } catch (IncompletePeriodException $e) {
            $this->closeErrors = $e->problems;

            return;
        } catch (PeriodClosedException $e) {
            $this->closeErrors = [$e->getMessage()];

            return;
        }

        unset($this->period, $this->summary, $this->reopenRefusal, $this->statements);
        $this->closeErrors = [];
    }

    /**
     * Fiches des locataires d'un mois clôturé, pour le partage (EF-37) ; vide en brouillon.
     *
     * @return array<int, \App\Modules\Metering\Data\TenantStatement>
     */
    #[Computed]
    public function statements(): array
    {
        $period = $this->period;
        if ($period === null || ! $period->isClosed()) {
            return [];
        }

        $statements = [];
        foreach ($this->summary->tenants as $tenant) {
            $occupancy = \App\Models\Occupancy::query()->with(['tenant', 'unit'])->find($tenant['occupancyId']);
            $statements[$tenant['occupancyId']] = app(\App\Modules\Metering\Queries\BuildTenantStatement::class)->execute($period, $occupancy);
        }

        return $statements;
    }

    /**
     * Pourquoi ce mois clôturé ne peut pas être rouvert, ou null s'il le peut (EF-34).
     */
    #[Computed]
    public function reopenRefusal(): ?string
    {
        $period = $this->period;

        return $period === null || ! $period->isClosed() ? null : app(ReopenBillingPeriod::class)->refusal($period);
    }

    public function reopen(): void
    {
        $this->authorize('update', $this->house);
        $period = $this->period ?? abort(404);
        $this->resetErrorBag('reopenReason');

        try {
            app(ReopenBillingPeriod::class)->execute($period, $this->reopenReason);
        } catch (ReopenRefusedException $e) {
            $this->addError('reopenReason', $e->getMessage());

            return;
        }

        $this->reopening = false;
        $this->reopenReason = '';
        unset($this->period, $this->summary, $this->reopenRefusal, $this->statements);
    }

    public function render(): mixed
    {
        return $this->view()->title('Résultat · '.Str::ucfirst(CarbonImmutable::createFromFormat('!Y-m', $this->mois)->locale('fr')->isoFormat('MMMM YYYY')));
    }
};
?>

@php
    $summary = $this->summary;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $mois)->locale('fr')->isoFormat('MMMM YYYY');
    $fcfa = fn (int $amount): string => \App\Shared\Support\Milli::format($amount * 1000).' FCFA';
    $qty = fn (int $milli, $utility): string => \App\Shared\Support\Milli::format($milli).' '.$utility->unit();
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('periodes.show', [$house, $mois]) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← Saisie de {{ $monthLabel }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Participation aux frais — {{ $monthLabel }}</h1>
        <p class="text-sm text-sand-600">{{ $house->name }}</p>
        @if ($summary)
            <x-ui.badge class="mt-2" :tone="$summary->period->isClosed() ? 'success' : 'neutral'">
                {{ $summary->period->isClosed() ? 'Clôturé le '.$summary->period->closed_at->timezone(config('app.timezone'))->format('d/m/Y') : 'Brouillon — calcul en direct' }}
            </x-ui.badge>
            @if ($summary->period->reopened_at)
                <p class="mt-2 text-xs text-sand-600">
                    Rouvert le {{ $summary->period->reopened_at->timezone(config('app.timezone'))->format('d/m/Y') }} : {{ $summary->period->reopen_reason }}
                </p>
            @endif
        @endif
    </div>

    @if ($summary === null)
        <x-ui.card role="status">
            <p class="text-sm text-sand-600">Rien n'est encore saisi pour ce mois.</p>
            <x-ui.button as="a" href="{{ route('periodes.show', [$house, $mois]) }}" wire:navigate class="mt-4">Saisir les relevés</x-ui.button>
        </x-ui.card>
    @else
        @if ($summary->problems !== [] || $closeErrors !== [])
            <x-ui.card class="space-y-2 border-warning-border bg-warning-bg">
                <h2 class="text-base font-semibold text-sand-900">À compléter avant la clôture</h2>
                <ul class="list-disc pl-5 space-y-1 text-sm text-sand-900">
                    @foreach (array_unique([...$closeErrors, ...$summary->problems]) as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('periodes.show', [$house, $mois]) }}" wire:navigate class="inline-block text-sm font-medium text-primary-700 hover:underline">Compléter la saisie</a>
            </x-ui.card>
        @endif

        @foreach ($summary->utilities as $block)
            @php
                $utility = $block['utility'];
            @endphp
            <x-ui.card class="space-y-4" wire:key="block-{{ $utility->value }}">
                <div>
                    <h2 class="text-base font-semibold text-sand-900">Participation aux frais {{ \App\Modules\Metering\Queries\ComputeBillingPeriod::of($utility->label()) }}</h2>
                    <p class="text-sm text-sand-700">{{ $block['priceLabel'] }}</p>
                    <p class="text-xs text-sand-500">
                        Facture : {{ $block['bill'] === null ? 'non renseignée' : $fcfa($block['bill']) }}
                        · Σ des décompteurs : {{ $qty($block['totalConsumption'], $utility) }}
                        @if ($block['countingGap'] !== null)
                            · écart de comptage : {{ $qty($block['countingGap'], $utility) }} ({{ $block['countingGapPct'] }} %), pour information
                        @endif
                    </p>
                </div>

                <ul class="divide-y divide-sand-200">
                    @foreach ($block['lines'] as $line)
                        <li class="py-2.5 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium {{ $line['occupancyId'] === null ? 'text-sand-600' : 'text-sand-900' }}">
                                    {{ $line['unitLabel'] }} — {{ $line['payer'] }}
                                </p>
                                <p class="text-xs text-sand-600">
                                    {{ $qty($line['consumption'], $utility) }}
                                    @if ($line['creditApplied'] > 0) · dont {{ $qty($line['creditApplied'], $utility) }} de crédit déduit @endif
                                    @if ($line['creditBalance'] > 0) · crédit restant : {{ $qty($line['creditBalance'], $utility) }} @endif
                                </p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if ($line['estimated'])
                                        <x-ui.badge tone="warning" size="sm">Estimée</x-ui.badge>
                                    @endif
                                    @if ($line['regularization'])
                                        <x-ui.badge tone="info" size="sm">Régularisation</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-sand-900 tabular-nums whitespace-nowrap">{{ $fcfa($line['amount']) }}</p>
                        </li>
                    @endforeach
                </ul>

                <dl class="space-y-1 text-sm border-t border-sand-200 pt-3">
                    <div class="flex justify-between gap-3"><dt class="text-sand-700">Total des participations des locataires</dt><dd class="font-semibold tabular-nums whitespace-nowrap">{{ $fcfa($block['tenantsTotal']) }}</dd></div>
                    @if ($block['vacantTotal'] > 0)
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">Part du propriétaire (logements vacants)</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($block['vacantTotal']) }}</dd></div>
                    @endif
                    @if ($block['ownerRemainder'] !== null)
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">Reste à la charge du propriétaire</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($block['ownerRemainder']) }}</dd></div>
                    @endif
                    @if ($block['collectionGap'] !== null)
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">Écart d'encaissement (information)</dt><dd class="tabular-nums whitespace-nowrap">{{ ($block['collectionGap'] >= 0 ? '+' : '−').\App\Shared\Support\Milli::format(abs($block['collectionGap']) * 1000) }} FCFA</dd></div>
                    @endif
                </dl>
            </x-ui.card>
        @endforeach

        @if ($summary->tenants !== [])
            <x-ui.card class="space-y-3">
                <h2 class="text-base font-semibold text-sand-900">Récapitulatif par locataire</h2>
                @foreach ($summary->tenants as $tenant)
                    <div class="rounded-[var(--radius)] border border-sand-200 p-3 text-sm" wire:key="tenant-{{ $loop->index }}">
                        <p class="font-semibold text-sand-900">{{ $tenant['unitLabel'] }} — {{ $tenant['name'] }}
                            @if ($tenant['estimated']) <x-ui.badge tone="warning" size="sm">Estimée</x-ui.badge> @endif
                        </p>
                        <dl class="mt-1 space-y-0.5">
                            @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                                @isset($tenant['amounts'][$utility->value])
                                    <div class="flex justify-between"><dt class="text-sand-600">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }}</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($tenant['amounts'][$utility->value]) }}</dd></div>
                                @endisset
                            @endforeach
                            <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($tenant['total']) }}</dd></div>
                            @if ($tenant['settlement'])
                                <div class="flex justify-between text-success"><dt>Crédit restant remboursé</dt><dd class="tabular-nums whitespace-nowrap">−{{ $fcfa($tenant['settlement']['deducted']) }}</dd></div>
                                <div class="flex justify-between font-semibold"><dt>Net à payer</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($tenant['net']) }}</dd></div>
                                @if ($tenant['settlement']['refundDue'] > 0)
                                    <div class="flex justify-between font-semibold text-danger"><dt>À rembourser au locataire</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($tenant['settlement']['refundDue']) }}</dd></div>
                                @endif
                            @endif
                        </dl>

                        @php
                            $statement = $this->statements[$tenant['occupancyId']] ?? null;
                        @endphp
                        @if ($statement)
                            {{-- EF-37 : partage en un clic. --}}
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <x-ui.button as="a" href="{{ $statement->whatsappUrl() }}" target="_blank" rel="noopener" variant="outline" size="sm">WhatsApp</x-ui.button>
                                <x-ui.button as="a" href="{{ $statement->smsUrl() }}" variant="outline" size="sm">SMS</x-ui.button>
                                <x-ui.button as="a" href="{{ route('periodes.statement', [$house, $mois, $tenant['occupancyId']]) }}" variant="outline" size="sm">Fiche PDF</x-ui.button>
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($summary->creditRefunds > 0)
                    <p class="text-xs text-sand-600">Propriétaire — remboursement de crédit : {{ $fcfa($summary->creditRefunds) }}.</p>
                @endif
            </x-ui.card>
        @endif

        @if ($summary->period->isClosed())
            <x-ui.card class="space-y-3">
                <h2 class="text-base font-semibold text-sand-900">Corriger ce mois</h2>
                @if ($this->reopenRefusal !== null)
                    <p class="text-sm text-sand-600">{{ $this->reopenRefusal }}</p>
                @elseif ($reopening)
                    <form wire:submit="reopen" class="space-y-3">
                        <p class="text-sm text-sand-700">
                            Les montants figés de {{ $monthLabel }} seront effacés ; les relevés et les factures saisis restent.
                            Après correction, clôturez à nouveau pour recalculer.
                        </p>
                        <div class="flex flex-col gap-1">
                            <label for="reopen-reason" class="text-sm font-medium text-sand-800">Motif de la réouverture</label>
                            <textarea id="reopen-reason" wire:model="reopenReason" rows="3" required
                                class="block w-full rounded-[var(--radius)] border border-sand-300 bg-white text-base text-sand-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-300"
                                placeholder="Par exemple : index de la Chambre 2 mal recopié"></textarea>
                            @error('reopenReason') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="danger" size="sm">Rouvrir {{ $monthLabel }}</x-ui.button>
                            <x-ui.button variant="outline" size="sm" wire:click="$set('reopening', false)">Annuler</x-ui.button>
                        </div>
                    </form>
                @else
                    <p class="text-sm text-sand-600">Une erreur de saisie ? Ce mois est le dernier clôturé : vous pouvez le rouvrir pour le corriger.</p>
                    <x-ui.button variant="outline" size="sm" wire:click="$set('reopening', true)">Rouvrir ce mois</x-ui.button>
                @endif
            </x-ui.card>
        @endif

        @if ($summary->canClose)
            <x-ui.card class="space-y-3">
                <p class="text-sm text-sand-700">
                    La clôture fige les montants de {{ $monthLabel }} : relevés, factures et prix ne pourront plus être modifiés.
                </p>
                <x-ui.button class="w-full" wire:click="close" wire:confirm="Clôturer {{ $monthLabel }} ? Les montants seront figés.">
                    Clôturer {{ $monthLabel }}
                </x-ui.button>
            </x-ui.card>
        @endif
    @endif
</div>
