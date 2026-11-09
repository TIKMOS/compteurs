{{-- Une ligne de la liste des impayés (page Paiements). --}}
@props(['row', 'fcfa'])

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
