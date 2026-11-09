<?php

use App\Models\House;
use App\Models\SubMeter;
use App\Modules\Metering\Actions\EstimateMeterReading;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\ReplaceSubMeter;
use App\Modules\Metering\Exceptions\EstimationImpossibleException;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Livewire\MonthSheet;
use App\Shared\Enums\Utility;
use App\Shared\Support\FrenchDecimal;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-07, UC-08, UC-10, UC-11, UC-12 : saisie d'un mois — index de fin de mois de chaque
 * décompteur (enregistrés champ par champ, EF-21 / ENF-03), estimation, remplacement de
 * décompteur, factures SBEE et SONEB.
 */
new class extends Component
{
    use \Livewire\WithFileUploads;

    /** @var array<int, mixed> photo téléversée par décompteur (id), traitée aussitôt */
    public array $photos = [];
    #[Locked]
    public House $house;

    /** « 2026-11 » */
    #[Locked]
    public string $mois;

    /** @var array<int, string> index saisi par décompteur (id) */
    public array $indexes = [];

    /** @var array<string, string> montant de la facture par fluide, « 62 055 » */
    public array $amounts = ['electricity' => '', 'water' => ''];

    /** @var array<string, string> consommation du compteur principal par fluide */
    public array $mainConsumptions = ['electricity' => '', 'water' => ''];

    #[Locked]
    public ?int $replacingMeterId = null;

    public string $replaceDate = '';

    public string $removalIndex = '';

    public string $newInstallIndex = '0';

    public string $newSerial = '';

    /** @var array<int, string> confirmation « enregistré » par décompteur */
    public array $saved = [];

    public function mount(House $house, string $mois): void
    {
        $this->authorize('update', $house);
        $this->house = $house->load(['mainMeters', 'pricings']);

        $month = CarbonImmutable::createFromFormat('!Y-m', $mois);
        abort_if($month === false || $month->format('Y-m') !== $mois || $month->isAfter(CarbonImmutable::now()->startOfMonth()), 404);
        $this->mois = $mois;

        $this->fillFromSheet();
    }

    #[Computed]
    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->mois);
    }

    #[Computed]
    public function sheet(): MonthSheet
    {
        return MonthSheet::build($this->house, $this->month);
    }

    public function updatedIndexes(string $value, string $meterId): void
    {
        $meter = $this->meter((int) $meterId);
        $this->resetErrorBag("indexes.{$meterId}");
        unset($this->saved[(int) $meterId]);

        $value = trim($value);
        $index = $value === '' ? null : FrenchDecimal::parse($value, 3);
        if ($value !== '' && ($index === null || strlen($index) > 13)) {
            $this->addError("indexes.{$meterId}", 'Index illisible : saisissez le chiffre affiché, ex. 1 392 ou 199,5.');

            return;
        }

        try {
            app(RecordMonthEndReading::class)->execute($this->openPeriod(), $meter, $index);
            $this->saved[$meter->id] = $index === null ? 'Saisie effacée' : 'Enregistré';
        } catch (ReadingRejectedException|PeriodClosedException $e) {
            $this->addError("indexes.{$meterId}", $e->getMessage());
        }

        unset($this->sheet);
    }

    /**
     * Photo du décompteur, jointe au relevé de fin de mois dès qu'elle est choisie (lot 3).
     */
    public function updatedPhotos(mixed $value, string $meterId): void
    {
        $this->resetErrorBag("photos.{$meterId}");
        $this->validate(
            ["photos.{$meterId}" => ['required', 'image', 'max:10240']],
            ["photos.{$meterId}.image" => 'Choisissez une photo (JPEG, PNG ou WebP).', "photos.{$meterId}.max" => 'Photo trop lourde (10 Mo au plus).'],
        );

        $reading = $this->monthEndReading((int) $meterId);
        if ($reading === null) {
            $this->addError("photos.{$meterId}", 'Saisissez d\'abord l\'index : la photo accompagne le relevé.');

            return;
        }

        try {
            app(\App\Modules\Metering\Actions\AttachReadingPhoto::class)->execute($reading, $this->photos[$meterId]);
            $this->saved[(int) $meterId] = 'Photo enregistrée';
        } catch (PeriodClosedException $e) {
            $this->addError("photos.{$meterId}", $e->getMessage());
        }

        unset($this->photos[$meterId], $this->sheet);
    }

    public function removePhoto(int $meterId): void
    {
        $reading = $this->monthEndReading($meterId);
        if ($reading !== null) {
            try {
                app(\App\Modules\Metering\Actions\AttachReadingPhoto::class)->execute($reading, null);
            } catch (PeriodClosedException $e) {
                $this->addError("photos.{$meterId}", $e->getMessage());
            }
        }

        unset($this->sheet);
    }

    private function monthEndReading(int $meterId): ?\App\Models\MeterReading
    {
        $meter = $this->meter($meterId);
        $period = $this->sheet->period;

        return $period === null ? null : $meter->readings()
            ->where('billing_period_id', $period->id)
            ->where('kind', \App\Shared\Enums\ReadingKind::MonthEnd)
            ->first();
    }

    public function estimate(int $meterId): void
    {
        $meter = $this->meter($meterId);
        $this->resetErrorBag("indexes.{$meterId}");

        try {
            $reading = app(EstimateMeterReading::class)->execute($this->openPeriod(), $meter);
            $this->indexes[$meterId] = Milli::format(Milli::fromDecimal($reading->index_value));
            $this->saved[$meterId] = 'Estimé';
        } catch (EstimationImpossibleException|ReadingRejectedException|PeriodClosedException $e) {
            $this->addError("indexes.{$meterId}", $e->getMessage());
        }

        unset($this->sheet);
    }

    public function startReplacement(int $meterId): void
    {
        $this->meter($meterId);
        $this->replacingMeterId = $meterId;
        $this->replaceDate = min(CarbonImmutable::today(), $this->month->endOfMonth())->toDateString();
        $this->removalIndex = '';
        $this->newInstallIndex = '0';
        $this->newSerial = '';
        $this->resetErrorBag();
    }

    public function cancelReplacement(): void
    {
        $this->replacingMeterId = null;
        $this->resetErrorBag();
    }

    public function replace(): void
    {
        $meter = $this->meter((int) $this->replacingMeterId);

        $this->validate([
            'replaceDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'newSerial' => ['nullable', 'string', 'max:60'],
        ], [], ['replaceDate' => 'date du remplacement', 'newSerial' => 'numéro de série']);

        $removal = FrenchDecimal::parse($this->removalIndex, 3);
        $install = FrenchDecimal::parse($this->newInstallIndex, 3);
        if ($removal === null) {
            $this->addError('removalIndex', "Saisissez l'index affiché par l'ancien décompteur au moment de la dépose.");
        }
        if ($install === null) {
            $this->addError('newInstallIndex', "Saisissez l'index du nouveau décompteur à la pose (souvent 0).");
        }
        if ($removal === null || $install === null) {
            return;
        }

        try {
            app(ReplaceSubMeter::class)->execute($meter, CarbonImmutable::createFromFormat('!Y-m-d', $this->replaceDate), $removal, $install, $this->newSerial);
        } catch (ReadingRejectedException $e) {
            $this->addError('removalIndex', $e->getMessage());

            return;
        } catch (OccupancyRejectedException|PeriodClosedException $e) {
            $this->addError('replaceDate', $e->getMessage());

            return;
        }

        $this->replacingMeterId = null;
        unset($this->sheet);
        $this->fillFromSheet();
    }

    public function updatedAmounts(string $value, string $utility): void
    {
        $this->saveBill(Utility::from($utility));
    }

    public function updatedMainConsumptions(string $value, string $utility): void
    {
        $this->saveBill(Utility::from($utility));
    }

    public function render(): mixed
    {
        return $this->view()->title(\Illuminate\Support\Str::ucfirst($this->month->locale('fr')->isoFormat('MMMM YYYY')).' · '.$this->house->name);
    }

    private function saveBill(Utility $utility): void
    {
        $key = $utility->value;
        $this->resetErrorBag(["amounts.{$key}", "mainConsumptions.{$key}"]);

        $amountInput = trim($this->amounts[$key]);
        $amount = $amountInput === '' ? null : FrenchDecimal::parse($amountInput, 0);
        if ($amountInput !== '' && ($amount === null || strlen($amount) > 10)) {
            $this->addError("amounts.{$key}", 'Saisissez le montant total en FCFA, sans décimales (ex. 62 055).');

            return;
        }

        $consumptionInput = trim($this->mainConsumptions[$key]);
        $consumption = $consumptionInput === '' ? null : FrenchDecimal::parse($consumptionInput, 3);
        if ($consumptionInput !== '' && ($consumption === null || strlen($consumption) > 13)) {
            $this->addError("mainConsumptions.{$key}", "Consommation illisible, en {$utility->unit()} (ex. 490).");

            return;
        }

        try {
            app(RecordUtilityBill::class)->execute($this->openPeriod(), $utility, $amount === null ? null : (int) $amount, $consumption);
        } catch (PeriodClosedException $e) {
            $this->addError("amounts.{$key}", $e->getMessage());
        }

        unset($this->sheet);
    }

    private function openPeriod(): \App\Models\BillingPeriod
    {
        $this->authorize('update', $this->house);

        return app(OpenBillingPeriod::class)->execute($this->house, $this->month);
    }

    /**
     * Décompteur d'un logement de cette maison, sinon 404 (ENF-09).
     */
    private function meter(int $id): SubMeter
    {
        $this->authorize('update', $this->house);

        return SubMeter::query()
            ->whereKey($id)
            ->whereHas('unit', fn ($q) => $q->where('house_id', $this->house->id))
            ->firstOrFail();
    }

    private function fillFromSheet(): void
    {
        $this->indexes = [];
        foreach ($this->sheet->units as $row) {
            foreach ($row['meters'] as $meter) {
                $reading = $meter['monthEnd'];
                $this->indexes[$meter['meter']->id] = $reading === null ? '' : Milli::format(Milli::fromDecimal($reading->index_value));
            }
        }

        foreach (Utility::cases() as $utility) {
            $bill = $this->sheet->period?->bill($utility);
            $this->amounts[$utility->value] = $bill?->total_amount === null ? '' : Milli::format($bill->total_amount * 1000);
            $this->mainConsumptions[$utility->value] = $bill?->main_consumption === null ? '' : Milli::format(Milli::fromDecimal($bill->main_consumption));
        }
    }
};
?>

@php
    $progress = $this->sheet->progress();
    $prev = $this->month->subMonthNoOverflow();
    $next = $this->month->addMonthNoOverflow();
    $closed = $this->sheet->period?->isClosed() ?? false;
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <div class="mt-2 flex items-center justify-between gap-2">
            <a href="{{ route('periodes.show', [$house, $prev->format('Y-m')]) }}" wire:navigate class="px-2 py-2 text-sand-500 hover:text-sand-900" aria-label="Mois précédent">‹</a>
            <h1 class="text-2xl font-bold text-sand-900 text-center">{{ \Illuminate\Support\Str::ucfirst($this->month->locale('fr')->isoFormat('MMMM YYYY')) }}</h1>
            @if ($next->lessThanOrEqualTo(now()->startOfMonth()))
                <a href="{{ route('periodes.show', [$house, $next->format('Y-m')]) }}" wire:navigate class="px-2 py-2 text-sand-500 hover:text-sand-900" aria-label="Mois suivant">›</a>
            @else
                <span class="px-2 py-2 text-sand-200" aria-hidden="true">›</span>
            @endif
        </div>
        <div class="mt-2 flex flex-wrap items-center justify-center gap-2 text-sm">
            <x-ui.badge :tone="$closed ? 'success' : 'neutral'">{{ $closed ? 'Clôturé' : 'Brouillon' }}</x-ui.badge>
            <span class="text-sand-600">
                {{ $progress['done'] }}/{{ $progress['needed'] }} relevés saisis{{ $progress['estimated'] ? ', dont '.$progress['estimated'].' estimé'.($progress['estimated'] > 1 ? 's' : '') : '' }}
            </span>
        </div>
    </div>

    @if (! $closed && $this->sheet->period?->reopened_at)
        <div class="rounded-[var(--radius)] border border-warning-border bg-warning-bg px-3 py-2 text-sm text-sand-900" role="status">
            Mois rouvert le {{ $this->sheet->period->reopened_at->timezone(config('app.timezone'))->format('d/m/Y') }} :
            {{ $this->sheet->period->reopen_reason }}. Corrigez, puis clôturez à nouveau.
        </div>
    @endif

    <p class="text-sm text-sand-600">
        Chaque index est enregistré dès que vous quittez le champ : vous pouvez reprendre plus tard.
    </p>

    <div wire:offline class="rounded-[var(--radius)] border border-warning-border bg-warning-bg px-3 py-2 text-sm text-sand-900" role="status">
        Hors connexion : continuez la saisie, les index sont gardés sur ce téléphone et envoyés au retour du réseau.
    </div>

    @forelse ($this->sheet->units as $row)
        <x-ui.card class="space-y-4" wire:key="unit-{{ $row['unit']->id }}">
            <div>
                <h2 class="text-base font-semibold text-sand-900">{{ $row['unit']->label }}</h2>
                <p class="text-sm {{ $row['occupants'] === [] ? 'text-warning' : 'text-sand-600' }}">
                    {{ $row['occupants'] === [] ? 'Vacant tout le mois (à la charge du propriétaire)' : implode(', ', $row['occupants']) }}
                </p>
            </div>

            @foreach ($row['meters'] as $m)
                @php
                    $meter = $m['meter'];
                    $id = $meter->id;
                    $reading = $m['monthEnd'];
                @endphp

                <div class="space-y-2 border-t border-sand-200 pt-3" wire:key="meter-{{ $id }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-sand-900">{{ \Illuminate\Support\Str::ucfirst($m['utility']->label()) }} ({{ $m['utility']->unit() }})</h3>
                        @if ($meter->replaces_sub_meter_id)
                            <span class="text-xs text-sand-500">nouveau décompteur</span>
                        @endif
                    </div>
                    <p class="text-xs text-sand-600">
                        Début : {{ $m['startLabel'] }}
                        @foreach ($m['events'] as $event)
                            <br>{{ \Illuminate\Support\Str::ucfirst($event) }}
                        @endforeach
                    </p>

                    @if ($m['needsMonthEnd'])
                        <div class="flex items-end gap-2">
                            {{-- ENF-03 / ENF-04 : envoi au départ du champ ; sans réseau, l'index est gardé
                                 sur le téléphone et envoyé au retour (resources/js/offline-queue.js). --}}
                            <div class="flex-1" x-data="offlineIndex({ page: @js($house->ulid.'/'.$mois), meterId: {{ $id }} })" wire:ignore.self>
                                <x-ui.input
                                    id="index-{{ $id }}"
                                    label="Index fin {{ $this->month->locale('fr')->isoFormat('MMMM') }}"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    x-ref="input"
                                    x-on:change="save()"
                                    :value="$indexes[$id] ?? ''"
                                    :error="$errors->first('indexes.'.$id)"
                                    :disabled="$closed"
                                />
                                <p x-show="pending" x-cloak class="mt-1 text-xs font-medium text-warning" role="status">
                                    En attente du réseau : cet index sera envoyé dès le retour de la connexion.
                                </p>
                            </div>
                            @unless ($closed || ($reading && ! $reading->is_estimated))
                                <x-ui.button variant="outline" size="md" wire:click="estimate({{ $id }})">Estimer</x-ui.button>
                            @endunless
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            @if ($reading?->is_estimated)
                                <x-ui.badge tone="warning" size="sm">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    Estimée
                                </x-ui.badge>
                                <span class="text-sand-600">{{ $reading->estimation_basis }}</span>
                            @endif
                            @if ($reading)
                                @if ($reading->photo_path)
                                    <a href="{{ route('readings.photo', [$house, $reading->id]) }}" target="_blank" class="font-medium text-primary-700 underline">Voir la photo</a>
                                    @unless ($closed)
                                        <button type="button" wire:click="removePhoto({{ $id }})" class="text-danger hover:underline">Retirer</button>
                                    @endunless
                                @elseif (! $closed)
                                    <label class="cursor-pointer font-medium text-primary-700 underline">
                                        Ajouter une photo
                                        <input type="file" accept="image/*" capture="environment" class="sr-only" wire:model="photos.{{ $id }}" />
                                    </label>
                                @endif
                                <span wire:loading wire:target="photos.{{ $id }}" class="text-sand-600">Envoi de la photo…</span>
                            @endif
                            @error('photos.'.$id) <span class="text-danger">{{ $message }}</span> @enderror
                            @if ($m['consumption'] !== null)
                                <span class="text-sand-600">Consommation du mois : {{ \App\Shared\Support\Milli::format($m['consumption']) }} {{ $m['utility']->unit() }}</span>
                            @endif
                            @if (isset($saved[$id]) && ! $errors->has('indexes.'.$id))
                                <span class="text-success font-medium" role="status">{{ $saved[$id] }}</span>
                            @endif
                        </div>
                    @else
                        <p class="text-xs text-sand-600">Déposé le {{ $meter->removed_on?->format('d/m/Y') }} : pas de relevé de fin de mois.</p>
                    @endif

                    @if ($meter->isActive() && ! $closed)
                        @if ($replacingMeterId === $id)
                            <form wire:submit="replace" class="space-y-3 rounded-[var(--radius)] border border-sand-200 bg-sand-50 p-3">
                                <p class="text-sm font-medium text-sand-900">Remplacement du décompteur</p>
                                <x-ui.input id="replace-date" label="Date du remplacement" type="date" wire:model="replaceDate" :error="$errors->first('replaceDate')" max="{{ now()->toDateString() }}" />
                                <x-ui.input id="removal-index" label="Index de l'ancien à la dépose" inputmode="decimal" wire:model="removalIndex" :error="$errors->first('removalIndex')" />
                                <x-ui.input id="new-index" label="Index du nouveau à la pose" inputmode="decimal" wire:model="newInstallIndex" :error="$errors->first('newInstallIndex')" hint="Souvent 0. Sert aussi pour un compteur remis à zéro." />
                                <x-ui.input id="new-serial" label="Numéro de série du nouveau (facultatif)" wire:model="newSerial" maxlength="60" :error="$errors->first('newSerial')" />
                                <div class="flex gap-2">
                                    <x-ui.button type="submit" size="sm">Enregistrer le remplacement</x-ui.button>
                                    <x-ui.button variant="outline" size="sm" wire:click="cancelReplacement">Annuler</x-ui.button>
                                </div>
                            </form>
                        @else
                            <button type="button" wire:click="startReplacement({{ $id }})" class="text-xs font-medium text-primary-700 hover:underline">
                                Décompteur changé ou remis à zéro ?
                            </button>
                        @endif
                    @endif
                </div>
            @endforeach
        </x-ui.card>
    @empty
        <x-ui.card class="text-center" role="status">
            <p class="text-sm text-sand-600">Aucun décompteur en place ce mois-ci. Ajoutez des logements et leurs décompteurs depuis la fiche de la maison.</p>
        </x-ui.card>
    @endforelse

    <x-ui.card class="space-y-5">
        <div>
            <h2 class="text-base font-semibold text-sand-900">Factures du mois</h2>
            <p class="text-sm text-sand-600">Montant total TTC (abonnement, redevances et taxes compris).</p>
        </div>

        @foreach (\App\Shared\Enums\Utility::cases() as $utility)
            @php
                $rule = $house->pricingFor($utility, $this->month)?->rule();
                $required = $rule?->requiresBill() ?? true;
                $provider = $utility === \App\Shared\Enums\Utility::Electricity ? 'SBEE' : 'SONEB';
            @endphp
            <div class="space-y-3" wire:key="bill-{{ $utility->value }}">
                <h3 class="text-sm font-semibold text-sand-900">
                    {{ $provider }} — {{ $utility->label() }}
                    <span class="font-normal {{ $required ? 'text-sand-600' : 'text-sand-500' }}">
                        · {{ $required ? 'obligatoire pour clôturer' : 'facultative (prix fixé sans plafond)' }}
                    </span>
                </h3>
                <x-ui.input id="amount-{{ $utility->value }}" label="Montant de la facture (FCFA)" inputmode="numeric" autocomplete="off"
                    wire:model.blur="amounts.{{ $utility->value }}" :value="$amounts[$utility->value]" :error="$errors->first('amounts.'.$utility->value)" :disabled="$closed" />
                <x-ui.input id="main-{{ $utility->value }}" label="Consommation du compteur principal ({{ $utility->unit() }}, facultatif)" inputmode="decimal" autocomplete="off"
                    wire:model.blur="mainConsumptions.{{ $utility->value }}" :value="$mainConsumptions[$utility->value]" :error="$errors->first('mainConsumptions.'.$utility->value)" :disabled="$closed"
                    hint="Pour information : l'écart avec les décompteurs sera affiché." />
            </div>
        @endforeach
    </x-ui.card>

    <x-ui.button as="a" href="{{ route('periodes.summary', [$house, $mois]) }}" wire:navigate class="w-full">
        {{ $closed ? 'Voir le résultat' : 'Voir le calcul et clôturer' }}
    </x-ui.button>
</div>
