<?php

use App\Models\BillingPeriod;
use App\Models\CreditSettlement;
use App\Models\House;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Modules\Metering\Actions\CancelPayment;
use App\Modules\Metering\Actions\MarkCreditRefunded;
use App\Modules\Metering\Actions\RecordPayment;
use App\Modules\Metering\Exceptions\PaymentRejectedException;
use App\Modules\Metering\Queries\HouseBalances;
use App\Shared\Enums\PaymentMethod;
use App\Shared\Support\FrenchDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-18 : suivi des paiements d'une maison — reste à encaisser par locataire et par mois,
 * saisie et annulation de paiements, remboursements de crédit dus aux locataires sortis.
 */
new class extends Component
{
    #[Locked]
    public House $house;

    /** Ligne ouverte pour saisir un paiement : « occupation|période ». */
    #[Locked]
    public ?string $paying = null;

    public string $amount = '';

    public string $paidOn = '';

    public string $method = 'cash';

    public string $note = '';

    public ?string $statusMessage = null;

    public function mount(House $house): void
    {
        $this->authorize('view', $house);
        $this->house = $house;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function balances(): array
    {
        return app(HouseBalances::class)->execute($this->house);
    }

    public function startPayment(int $occupancyId, int $periodId, int $remaining): void
    {
        $this->authorize('update', $this->house);
        $this->paying = "{$occupancyId}|{$periodId}";
        $this->amount = $remaining > 0 ? (string) $remaining : '';
        $this->paidOn = now()->toDateString();
        $this->method = PaymentMethod::Cash->value;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        $this->paying = null;
        $this->resetErrorBag();
    }

    public function savePayment(): void
    {
        $this->authorize('update', $this->house);
        [$occupancyId, $periodId] = array_map('intval', explode('|', (string) $this->paying));

        $this->validate([
            'paidOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::selectable()))],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['paidOn.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.'], ['paidOn' => 'date', 'method' => 'moyen de paiement']);

        $amount = FrenchDecimal::parse($this->amount, 0);
        if ($amount === null || (int) $amount <= 0) {
            $this->addError('amount', 'Saisissez le montant reçu en FCFA, ex. 24 919.');

            return;
        }

        $period = BillingPeriod::query()->where('house_id', $this->house->id)->findOrFail($periodId);
        $occupancy = Occupancy::query()->whereHas('unit', fn ($q) => $q->where('house_id', $this->house->id))->findOrFail($occupancyId);

        try {
            app(RecordPayment::class)->execute($occupancy, $period, (int) $amount, CarbonImmutable::createFromFormat('!Y-m-d', $this->paidOn), PaymentMethod::from($this->method), auth()->user(), $this->note);
        } catch (PaymentRejectedException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->paying = null;
        $this->statusMessage = 'Paiement enregistré.';
        unset($this->balances);
    }

    public function cancelPayment(int $paymentId): void
    {
        $this->authorize('update', $this->house);
        $payment = Payment::query()->whereHas('billingPeriod', fn ($q) => $q->where('house_id', $this->house->id))->findOrFail($paymentId);

        app(CancelPayment::class)->execute($payment);

        $this->statusMessage = 'Paiement annulé.';
        unset($this->balances);
    }

    public function markRefunded(int $settlementId): void
    {
        $this->authorize('update', $this->house);
        $settlement = CreditSettlement::query()->whereHas('billingPeriod', fn ($q) => $q->where('house_id', $this->house->id))->findOrFail($settlementId);

        try {
            app(MarkCreditRefunded::class)->execute($settlement, CarbonImmutable::today());
        } catch (PaymentRejectedException $e) {
            $this->statusMessage = $e->getMessage();

            return;
        }

        $this->statusMessage = 'Remboursement marqué comme effectué.';
        unset($this->balances);
    }

    public function render(): mixed
    {
        return $this->view()->title('Paiements · '.$this->house->name);
    }
};
?>

@php
    $b = $this->balances;
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Paiements</h1>
    </div>

    @if ($statusMessage)
        <div class="rounded-[var(--radius)] border border-success-border bg-success-bg px-3 py-2 text-sm text-success" role="status">{{ $statusMessage }}</div>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <x-ui.card>
            <p class="text-xs text-sand-600">Reste à encaisser</p>
            <p class="text-lg font-bold tabular-nums whitespace-nowrap {{ $b['totalRemaining'] > 0 ? 'text-warning' : 'text-success' }}">{{ $fcfa($b['totalRemaining']) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-sand-600">À rembourser aux locataires</p>
            <p class="text-lg font-bold tabular-nums whitespace-nowrap {{ $b['totalRefunds'] > 0 ? 'text-danger' : 'text-sand-900' }}">{{ $fcfa($b['totalRefunds']) }}</p>
        </x-ui.card>
    </div>

    @if ($b['refunds'] !== [])
        <x-ui.card class="space-y-3">
            <h2 class="text-base font-semibold text-sand-900">Crédits à rembourser</h2>
            @foreach ($b['refunds'] as $refund)
                <div class="flex items-center justify-between gap-3 text-sm" wire:key="refund-{{ $refund->id }}">
                    <div class="min-w-0">
                        <p class="font-medium text-sand-900">{{ $refund->occupancy->tenant->name }} · {{ $refund->occupancy->unit->label }}</p>
                        <p class="text-xs text-sand-600">Sorti en {{ $refund->billingPeriod->label() }} · {{ $fcfa($refund->refund_due) }}</p>
                    </div>
                    <x-ui.button size="sm" variant="outline" wire:click="markRefunded({{ $refund->id }})"
                        wire:confirm="Confirmer le remboursement de {{ $fcfa($refund->refund_due) }} à {{ $refund->occupancy->tenant->name }} ?">
                        Marquer remboursé
                    </x-ui.button>
                </div>
            @endforeach
        </x-ui.card>
    @endif

    {{-- Liste des impayés de la maison : statut partiel ou impayé. --}}
    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Impayés</h2>
        @forelse ($b['unpaid'] as $row)
            <div class="flex items-center justify-between gap-3 text-sm" wire:key="unpaid-{{ $row['occupancy']->id }}-{{ $row['period']->id }}">
                <div class="min-w-0">
                    <p class="font-medium text-sand-900 truncate">{{ $row['occupancy']->unit->label }} — {{ $row['occupancy']->tenant->name }}</p>
                    <p class="text-xs text-sand-600">
                        {{ \Illuminate\Support\Str::ucfirst($row['period']->label()) }} · reste {{ $fcfa($row['remaining']) }}
                        @if ($row['paid'] > 0) (reçu {{ $fcfa($row['paid']) }}) @endif
                    </p>
                </div>
                <x-ui.badge :tone="$row['status']->tone()" size="sm">{{ $row['status']->label() }}</x-ui.badge>
            </div>
        @empty
            <p class="text-sm text-success" role="status">Aucun impayé : toutes les répartitions clôturées sont payées.</p>
        @endforelse
    </x-ui.card>

    <h2 class="pt-2 text-base font-semibold text-sand-900">Détail par locataire</h2>

    @forelse ($b['tenants'] as $t)
        @php
            $occupancy = $t['occupancy'];
        @endphp
        <x-ui.card class="space-y-3" wire:key="tenant-{{ $occupancy->id }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold text-sand-900">{{ $occupancy->unit->label }} — {{ $occupancy->tenant->name }}</h2>
                    @if ($occupancy->ended_on)
                        <p class="text-xs text-sand-600">Sorti le {{ $occupancy->ended_on->format('d/m/Y') }}</p>
                    @endif
                </div>
                <x-ui.badge :tone="$t['remaining'] > 0 ? 'warning' : 'success'">
                    {{ $t['remaining'] > 0 ? 'Reste '.$fcfa($t['remaining']) : 'À jour' }}
                </x-ui.badge>
            </div>

            <ul class="divide-y divide-sand-200">
                @foreach ($t['months'] as $m)
                    @php
                        $rowKey = $occupancy->id.'|'.$m['period']->id;
                    @endphp
                    <li class="py-2.5 space-y-2" wire:key="row-{{ $rowKey }}">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <div>
                                <p class="font-medium text-sand-900">{{ \Illuminate\Support\Str::ucfirst($m['period']->label()) }}</p>
                                <p class="text-xs text-sand-600">Dû {{ $fcfa($m['due']) }} · reçu {{ $fcfa($m['paid']) }}</p>
                                <x-ui.badge :tone="$m['status']->tone()" size="sm" class="mt-1">{{ $m['status']->label() }}</x-ui.badge>
                            </div>
                            @if ($m['remaining'] > 0)
                                @if ($paying !== $rowKey)
                                    <x-ui.button size="sm" wire:click="startPayment({{ $occupancy->id }}, {{ $m['period']->id }}, {{ $m['remaining'] }})">
                                        Encaisser
                                    </x-ui.button>
                                @endif
                            @else
                            @endif
                        </div>

                        @foreach ($m['payments'] as $p)
                            <div class="flex items-center justify-between gap-3 pl-3 text-xs text-sand-600" wire:key="payment-{{ $p->id }}">
                                <span>{{ $p->paid_on->format('d/m/Y') }} · {{ $p->method->label() }} · {{ $fcfa($p->amount) }}{{ $p->note ? ' · '.$p->note : '' }}</span>
                                <button type="button" class="text-danger hover:underline" wire:click="cancelPayment({{ $p->id }})" wire:confirm="Annuler ce paiement de {{ $fcfa($p->amount) }} ?">Annuler</button>
                            </div>
                        @endforeach

                        @if ($paying === $rowKey)
                            <form wire:submit="savePayment" class="space-y-3 rounded-[var(--radius)] border border-sand-200 bg-sand-50 p-3">
                                <x-ui.input id="amount" label="Montant reçu (FCFA)" inputmode="numeric" wire:model="amount" :error="$errors->first('amount')" />
                                <x-ui.input id="paid-on" label="Date" type="date" wire:model="paidOn" :error="$errors->first('paidOn')" max="{{ now()->toDateString() }}" />
                                <div class="flex flex-col gap-1">
                                    <label for="method" class="text-sm font-medium text-sand-800">Moyen de paiement</label>
                                    <select id="method" wire:model="method" class="block w-full h-11 rounded-[var(--radius)] border border-sand-300 bg-white text-base">
                                        @foreach (\App\Shared\Enums\PaymentMethod::selectable() as $pm)
                                            <option value="{{ $pm->value }}">{{ $pm->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-ui.input id="note" label="Note (facultatif)" wire:model="note" hint="Ex. référence Mobile Money" />
                                <div class="flex gap-2">
                                    <x-ui.button type="submit" size="sm">Enregistrer</x-ui.button>
                                    <x-ui.button variant="outline" size="sm" wire:click="cancelForm">Annuler</x-ui.button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @empty
        <x-ui.card role="status">
            <p class="text-sm text-sand-600">Aucun mois clôturé : les montants à encaisser apparaissent ici après la clôture d'un mois.</p>
        </x-ui.card>
    @endforelse
</div>
