{{-- Choix du mode de calcul d'un fluide (EF-39 à EF-41), dans un composant Livewire qui expose
     les tableaux $modes, $prices et $caps indexés par fluide. --}}
@props(['utility', 'modes'])

@php
    /** @var \App\Shared\Enums\Utility $utility */
    $key = $utility->value;
    $isFixed = ($modes[$key] ?? 'bill_split') === 'fixed_price';
@endphp

<fieldset class="space-y-3">
    <legend class="text-sm font-semibold text-sand-900">
        Mode de calcul — {{ $utility->label() }}
    </legend>

    @foreach (\App\Shared\Enums\PricingMode::cases() as $mode)
        <label class="flex items-start gap-3 rounded-[var(--radius)] border p-3 cursor-pointer {{ ($modes[$key] ?? 'bill_split') === $mode->value ? 'border-primary-500 bg-primary-50' : 'border-sand-200 bg-white' }}">
            <input
                type="radio"
                name="mode-{{ $key }}"
                value="{{ $mode->value }}"
                wire:model.live="modes.{{ $key }}"
                @checked(($modes[$key] ?? 'bill_split') === $mode->value)
                class="mt-1 h-4 w-4 border-sand-300 text-primary-600 focus:ring-primary-300"
            />
            <span>
                <span class="block text-sm font-medium text-sand-900">
                    {{ $mode === \App\Shared\Enums\PricingMode::BillSplit ? 'Mode 1 — ' : 'Mode 2 — ' }}{{ $mode->label() }}
                    @if ($mode === \App\Shared\Enums\PricingMode::BillSplit)
                        <span class="text-sand-500 font-normal">(par défaut)</span>
                    @endif
                </span>
                <span class="block text-xs text-sand-600 mt-0.5">{{ $mode->description() }}</span>
            </span>
        </label>
    @endforeach

    @if ($isFixed)
        <div class="space-y-3 rounded-[var(--radius)] border border-sand-200 bg-sand-50 p-3">
            <x-ui.input
                id="price-{{ $key }}"
                label="Prix par {{ $utility->unit() }} (FCFA)"
                inputmode="decimal"
                wire:model.blur="prices.{{ $key }}"
                :error="$errors->first('prices.'.$key)"
                hint="Par exemple 127,50"
                autocomplete="off"
            />

            <label class="flex items-start gap-3 text-sm text-sand-800">
                <input
                    type="checkbox"
                    wire:model.live="caps.{{ $key }}"
                    class="mt-0.5 h-4 w-4 rounded border-sand-300 text-primary-600 focus:ring-primary-300"
                />
                <span>
                    <span class="font-medium">Plafonner au prix réel</span>
                    <span class="block text-xs text-sand-600">
                        Recommandé. Le prix appliqué ne dépasse jamais le prix réel (facture ÷ consommations) ;
                        la facture du mois devient obligatoire.
                    </span>
                </span>
            </label>
        </div>
    @endif
</fieldset>
