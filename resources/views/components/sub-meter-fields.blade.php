{{-- Champs de pose d'un décompteur (EF-04), dans un composant Livewire qui utilise
     InteractsWithSubMeterForm. --}}
@props(['utility'])

@php
    /** @var \App\Shared\Enums\Utility $utility */
    $key = $utility->value;
@endphp

<div class="space-y-3">
    <x-ui.input
        id="index-{{ $key }}"
        label="Index de pose ({{ $utility->unit() }})"
        inputmode="decimal"
        wire:model="installIndexes.{{ $key }}"
        :error="$errors->first('installIndexes.'.$key)"
        hint="Le chiffre affiché sur le décompteur le jour de la pose."
        autocomplete="off"
    />

    <x-ui.input
        id="installed-{{ $key }}"
        label="Date de pose"
        type="date"
        wire:model="installedOn.{{ $key }}"
        :error="$errors->first('installedOn.'.$key)"
        max="{{ now()->toDateString() }}"
    />

    <x-ui.input
        id="serial-{{ $key }}"
        label="Numéro de série (facultatif)"
        wire:model="serials.{{ $key }}"
        :error="$errors->first('serials.'.$key)"
        autocomplete="off"
    />
</div>
