@props([
    /** Lignes de ConsumptionHistory, du mois le plus récent au plus ancien */
    'rows' => [],
    /** Afficher un lien vers le récapitulatif du mois (propriétaire) */
    'linkMonths' => true,
])

{{-- Lot 2, tranche 4 : historique des 12 derniers mois clôturés (graphique + tableau par fluide). --}}

@php
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $utilities = collect(\App\Shared\Enums\Utility::cases())
        ->filter(fn ($u) => collect($rows)->contains(fn ($r) => isset($r['utilities'][$u->value])));
@endphp

<x-ui.card {{ $attributes->class('space-y-5') }}>
    <h2 class="text-base font-semibold text-sand-900">Historique des 12 derniers mois</h2>

    @if ($rows === [])
        <p class="text-sm text-sand-600" role="status">Aucun mois clôturé pour l'instant : l'historique apparaîtra après la première clôture.</p>
    @else
        @foreach ($utilities as $utility)
            @php
                $chrono = array_reverse(array_values(array_filter($rows, fn ($r) => isset($r['utilities'][$utility->value]))));
                $points = array_map(fn ($r) => [
                    'short' => $r['period']->month->locale('fr')->isoFormat('MMM'),
                    'full' => \Illuminate\Support\Str::ucfirst($r['period']->label()),
                    'value' => $r['utilities'][$utility->value]['consumption'],
                    'estimated' => $r['utilities'][$utility->value]['estimated'],
                ], $chrono);
            @endphp

            <section class="space-y-3" wire:key="history-{{ $utility->value }}">
                <x-consumption-chart :utility="$utility" :points="$points" />

                <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }} : détail par mois</caption>
                        <thead>
                            <tr class="border-b border-sand-200 text-left text-xs text-sand-600">
                                <th scope="col" class="py-1.5 pr-2 font-medium">Mois</th>
                                <th scope="col" class="py-1.5 px-2 text-right font-medium">Conso.</th>
                                <th scope="col" class="py-1.5 px-2 text-right font-medium">Prix <span class="font-normal">(FCFA/{{ $utility->unit() }})</span></th>
                                <th scope="col" class="py-1.5 pl-2 text-right font-medium">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @isset($row['utilities'][$utility->value])
                                    @php $u = $row['utilities'][$utility->value]; @endphp
                                    <tr class="border-b border-sand-100 align-top" wire:key="h-{{ $utility->value }}-{{ $row['period']->id }}">
                                        <th scope="row" class="py-1.5 pr-2 text-left font-normal text-sand-900 whitespace-nowrap">
                                            @if ($linkMonths)
                                                <a href="{{ route('periodes.summary', [$row['period']->house, $row['period']->month->format('Y-m')]) }}" wire:navigate class="hover:underline">{{ $row['period']->month->locale('fr')->isoFormat('MMM YYYY') }}</a>
                                            @else
                                                {{ $row['period']->month->locale('fr')->isoFormat('MMM YYYY') }}
                                            @endif
                                            <span class="block text-xs {{ $u['estimated'] ? 'text-warning' : 'text-sand-500' }}">
                                                {{ $u['estimated'] ? 'Estimé' : 'Réel' }}{{ $u['regularized'] ? ' · régularisé' : '' }}
                                            </span>
                                        </th>
                                        <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap">{{ \App\Shared\Support\Milli::format($u['consumption']) }} {{ $utility->unit() }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums text-sand-700">
                                            @foreach ($u['prices'] as $price)<span class="block whitespace-nowrap">{{ $price }}</span>@endforeach
                                        </td>
                                        <td class="py-1.5 pl-2 text-right tabular-nums whitespace-nowrap font-medium">{{ $fcfa($u['amount']) }}</td>
                                    </tr>
                                @endisset
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach

        <p class="text-xs text-sand-500">
            Consommation facturée après crédit éventuel. « Estimé » : mois facturé sur un relevé estimé ;
            « régularisé » : le mois rattrape une estimation précédente.
        </p>
    @endif
</x-ui.card>
