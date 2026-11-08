{{-- Date et index par décompteur actif (EF-08), pour un composant qui utilise
     InteractsWithReadingForm. --}}
@props(['unit', 'dateLabel', 'hints' => []])

<div class="space-y-4">
    <x-ui.input
        id="read-on"
        :label="$dateLabel"
        type="date"
        wire:model="readOn"
        :error="$errors->first('readOn')"
        max="{{ now()->toDateString() }}"
        required
    />

    @foreach (\App\Shared\Enums\Utility::cases() as $utility)
        @if ($unit->activeSubMeter($utility))
            <x-ui.input
                id="index-{{ $utility->value }}"
                label="Index du décompteur d'{{ $utility->label() }} ({{ $utility->unit() }})"
                inputmode="decimal"
                wire:model="indexes.{{ $utility->value }}"
                :error="$errors->first('indexes.'.$utility->value)"
                :hint="$hints[$utility->value] ?? null"
                autocomplete="off"
                required
            />
        @else
            <p class="text-sm text-sand-600">Pas de décompteur d'{{ $utility->label() }} : aucun index à relever.</p>
        @endif
    @endforeach
</div>
