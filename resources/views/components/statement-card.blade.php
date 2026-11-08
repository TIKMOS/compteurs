{{-- Fiche d'un locataire à l'écran (EF-36) : même contenu que la fiche PDF. --}}
@props(['s', 'pdfUrl' => null])

@php
    /** @var \App\Modules\Metering\Data\TenantStatement $s */
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
@endphp

<x-ui.card class="space-y-3">
    <div>
        <h2 class="text-base font-semibold text-sand-900">{{ \Illuminate\Support\Str::ucfirst($s->monthLabel()) }}</h2>
        <p class="text-xs text-sand-600">
            {{ $s->house->name }} · {{ $s->unit->label }} · du {{ $s->from->format('d/m/Y') }} au {{ $s->to->format('d/m/Y') }}
        </p>
    </div>

    @foreach ($s->utilities as $u)
        <div class="space-y-1 text-sm">
            <p class="font-medium text-sand-900">
                {{ \Illuminate\Support\Str::ucfirst($u['utility']->label()) }}
                @if ($u['estimated']) <x-ui.badge tone="warning" size="sm">Estimée</x-ui.badge> @endif
                @if ($u['regularization']) <x-ui.badge tone="info" size="sm">Régularisation</x-ui.badge> @endif
            </p>
            @foreach ($u['segments'] as $segment)
                <p class="text-xs text-sand-600">{{ $segment['from'] }} → {{ $segment['to'] }} : {{ $qty($segment['consumption'], $u['utility']) }}</p>
            @endforeach
            @if ($u['creditApplied'] > 0)
                <p class="text-xs text-sand-600">Crédit déduit : {{ $qty($u['creditApplied'], $u['utility']) }}</p>
            @endif
            <p class="text-xs text-sand-600">{{ $u['priceLabel'] }}</p>
            <div class="flex justify-between gap-3">
                <span class="text-sand-700">Participation ({{ $qty($u['consumption'], $u['utility']) }})</span>
                <span class="font-medium tabular-nums whitespace-nowrap">{{ $fcfa($u['amount']) }}</span>
            </div>
        </div>
    @endforeach

    <dl class="space-y-0.5 border-t border-sand-200 pt-2 text-sm">
        <div class="flex justify-between gap-3 font-semibold"><dt>Total</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($s->total) }}</dd></div>
        @if ($s->creditRefunded !== null)
            <div class="flex justify-between gap-3 text-success"><dt>Crédit restant remboursé</dt><dd class="tabular-nums whitespace-nowrap">−{{ $fcfa($s->creditRefunded) }}</dd></div>
            <div class="flex justify-between gap-3 font-semibold"><dt>Net à payer</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($s->net()) }}</dd></div>
            @if ($s->refundDue > 0)
                <div class="flex justify-between gap-3 font-semibold text-danger"><dt>À vous rembourser</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($s->refundDue) }}</dd></div>
            @endif
        @endif
    </dl>

    @if ($pdfUrl)
        <x-ui.button as="a" href="{{ $pdfUrl }}" variant="outline" size="sm">Télécharger la fiche (PDF)</x-ui.button>
    @endif
</x-ui.card>
