<?php

use App\Models\House;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Exceptions\ComplianceAcknowledgementRequiredException;
use App\Modules\Metering\Exceptions\PricingEffectiveDateException;
use App\Modules\Metering\Livewire\Concerns\InteractsWithPricingForm;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-22 / EF-46 : changer le mode, le prix fixé ou le plafond d'un fluide, à partir d'un mois
 * entier (D-24) ; historique des versions et des confirmations de conformité (EF-49).
 */
new class extends Component
{
    use InteractsWithPricingForm;

    #[Locked]
    public House $house;

    /** @var array<string, string> mois d'effet par fluide, « 2026-11 » */
    public array $effectiveMonths = ['electricity' => '', 'water' => ''];

    #[Locked]
    public ?string $pendingUtility = null;

    public ?string $statusMessage = null;

    public function mount(House $house): void
    {
        $this->authorize('update', $house);
        $this->house = $house;
        $this->refreshHouse();
    }

    public function saveUtility(string $utility): void
    {
        $this->persist(Utility::from($utility), confirmed: false);
    }

    public function confirmCompliance(): void
    {
        if ($this->pendingUtility !== null) {
            $this->persist(Utility::from($this->pendingUtility), confirmed: true);
        }
    }

    /**
     * Mois proposés : du premier mois non clôturé aux 11 suivants.
     *
     * @return array<string, string> « 2026-11 » => « novembre 2026 »
     */
    public function monthOptions(): array
    {
        $first = $this->house->firstOpenMonth();
        $options = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $options[$month->format('Y-m')] = $month->locale('fr')->isoFormat('MMMM YYYY');
        }

        return $options;
    }

    public function render(): mixed
    {
        return $this->view()->title('Calcul des participations · '.$this->house->name);
    }

    private function persist(Utility $utility, bool $confirmed): void
    {
        $this->authorize('update', $this->house);
        $key = $utility->value;

        $this->validate([
            "modes.{$key}" => ['required', Rule::in(['bill_split', 'fixed_price'])],
            "effectiveMonths.{$key}" => ['required', Rule::in(array_keys($this->monthOptions()))],
        ], [], ["effectiveMonths.{$key}" => 'mois d\'effet']);

        $rule = $this->pricingRuleFor($utility);

        try {
            $version = app(ChangeUtilityPricing::class)->execute(
                house: $this->house,
                utility: $utility,
                rule: $rule,
                effectiveFrom: CarbonImmutable::createFromFormat('!Y-m', $this->effectiveMonths[$key]),
                by: auth()->user(),
                complianceConfirmed: $confirmed,
                ipAddress: request()->ip(),
            );
        } catch (ComplianceAcknowledgementRequiredException $e) {
            $this->complianceEvents = [];
            $this->pendingUtility = $key;
            $this->askComplianceConfirmation($utility, $e->kinds);

            return;
        } catch (PricingEffectiveDateException $e) {
            throw ValidationException::withMessages(["effectiveMonths.{$key}" => $e->getMessage()]);
        }

        $this->cancelCompliance();
        $this->pendingUtility = null;
        $this->refreshHouse();

        // Message propre au composant : la mise en page n'est pas redessinée par Livewire.
        $this->statusMessage = ($version === null
            ? 'Aucun changement à enregistrer.'
            : \Illuminate\Support\Str::ucfirst($utility->label()).' : nouveau calcul enregistré à partir de '
                .$version->effective_from->locale('fr')->isoFormat('MMMM YYYY').'.');
    }

    private function refreshHouse(): void
    {
        $this->house->load(['pricings.author', 'complianceAcknowledgements.user']);
        $this->fillPricingFrom($this->house);

        $default = $this->house->firstOpenMonth()->format('Y-m');
        $this->effectiveMonths = ['electricity' => $default, 'water' => $default];
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Calcul des participations</h1>
        <p class="text-sm text-sand-600">
            Un changement s'applique à des mois entiers, à partir du mois choisi. Les mois déjà clôturés ne changent jamais.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-[var(--radius)] border border-success-border bg-success-bg px-3 py-2 text-sm text-success" role="status">
            {{ $statusMessage }}
        </div>
    @endif

    @foreach (\App\Shared\Enums\Utility::cases() as $utility)
        <x-ui.card class="space-y-4" wire:key="pricing-{{ $utility->value }}">
            <div>
                <h2 class="text-base font-semibold text-sand-900">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }}</h2>
                <p class="text-sm text-sand-600">
                    Actuellement : {{ $house->currentPricing($utility)?->rule()->summary($utility->unit()) }}
                </p>
            </div>

            <x-pricing-fields :utility="$utility" :modes="$modes" />

            <div class="flex flex-col gap-1">
                <label for="month-{{ $utility->value }}" class="text-sm font-medium text-sand-800">À partir de</label>
                <select
                    id="month-{{ $utility->value }}"
                    wire:model="effectiveMonths.{{ $utility->value }}"
                    class="block w-full h-11 rounded-[var(--radius)] border border-sand-300 bg-white text-base text-sand-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-300"
                >
                    @foreach ($this->monthOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('effectiveMonths.'.$utility->value)
                    <p class="text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.button variant="primary" class="w-full sm:w-auto" wire:click="saveUtility('{{ $utility->value }}')">
                Enregistrer ({{ $utility->label() }})
            </x-ui.button>
        </x-ui.card>
    @endforeach

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Historique des prix</h2>
        <ul class="divide-y divide-sand-200">
            @foreach ($house->pricings->sortByDesc(fn ($p) => $p->effective_from->toDateString().$p->utility->value) as $version)
                <li class="py-2 text-sm" wire:key="version-{{ $version->id }}">
                    <p class="text-sand-900">
                        <span class="font-medium">{{ \Illuminate\Support\Str::ucfirst($version->utility->label()) }}</span>,
                        à partir de {{ $version->effective_from->locale('fr')->isoFormat('MMMM YYYY') }}
                    </p>
                    <p class="text-sand-600">{{ $version->rule()->summary($version->utility->unit()) }}</p>
                    <p class="text-xs text-sand-500">
                        Enregistré le {{ $version->updated_at->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}
                        par {{ $version->author->name }}
                    </p>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Confirmations de l'avertissement</h2>
        @forelse ($house->complianceAcknowledgements->sortByDesc('acknowledged_at') as $ack)
            <div class="text-sm" wire:key="ack-{{ $ack->id }}">
                <p class="text-sand-900">
                    {{ $ack->kind->label() }} — {{ $ack->utility->label() }}
                </p>
                <p class="text-xs text-sand-500">
                    Confirmé le {{ $ack->acknowledged_at->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}
                    par {{ $ack->user->name }}
                </p>
            </div>
        @empty
            <p class="text-sm text-sand-600">Aucune confirmation : cette maison n'a jamais utilisé le prix fixé.</p>
        @endforelse
    </x-ui.card>

    @if ($confirmingCompliance)
        <x-compliance-dialog :events="$complianceEvents" />
    @endif
</div>
