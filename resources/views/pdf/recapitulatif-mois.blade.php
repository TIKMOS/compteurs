{{-- Récapitulatif d'un mois clôturé pour le propriétaire, rendu par DomPDF. Noir et blanc. --}}
@php
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $num = fn (int $a): string => \App\Shared\Support\Milli::format($a * 1000);
    $qty = fn (int $m): string => \App\Shared\Support\Milli::format($m);
    $ucfirst = fn (string $t): string => \Illuminate\Support\Str::ucfirst($t);
    $signed = fn (int $a): string => ($a >= 0 ? '+ ' : '− ').$fcfa(abs($a));
    $sumUtility = fn (string $u, string $field): int => array_sum(array_map(fn ($r) => $r['utilities'][$u][$field] ?? 0, $rows));
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Récapitulatif — {{ $period->label() }}</title>
    @include('pdf.partials.styles')
    {{-- Document dense : espacements réduits pour tenir sur une page A4 avec quelques logements. --}}
    <style>td, th { padding: 2.5pt 5pt; } h2 { margin-top: 10pt; } .subtitle { margin-bottom: 6pt; }</style>
</head>
<body>
    <h1>Récapitulatif du mois — répartition des charges</h1>
    <p class="subtitle">{{ $ucfirst($period->label()) }} · {{ $house->name }}@if ($house->address) — {{ $house->address }}@endif</p>

    <h2>Participations par logement</h2>
    <table class="grid">
        <tr>
            <th>Logement</th>
            <th>Payeur</th>
            @foreach ($utilities as $u)
                <th class="num">{{ $ucfirst($u->label()) }} ({{ $u->unit() }})</th>
                <th class="num">{{ $ucfirst($u->label()) }} (FCFA)</th>
            @endforeach
            <th class="num">Total (FCFA)</th>
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['unit'] }}</td>
                <td>{{ $row['payer'] }}@if ($row['estimated']) <span class="tag">ESTIMÉ</span>@endif</td>
                @foreach ($utilities as $u)
                    @php $cell = $row['utilities'][$u->value] ?? null; @endphp
                    <td class="num">{{ $cell ? $qty($cell['consumption']) : '—' }}</td>
                    <td class="num">{{ $cell ? $num($cell['amount']) : '—' }}</td>
                @endforeach
                <td class="num">{{ $num($row['total']) }}</td>
            </tr>
        @endforeach
        <tr class="sum">
            <td colspan="2">Total</td>
            @foreach ($utilities as $u)
                <td class="num">{{ $qty($sumUtility($u->value, 'consumption')) }}</td>
                <td class="num">{{ $num($sumUtility($u->value, 'amount')) }}</td>
            @endforeach
            <td class="num">{{ $num(array_sum(array_column($rows, 'total'))) }}</td>
        </tr>
    </table>

    @foreach ($summary->utilities as $block)
        @php $u = $block['utility']; @endphp
        <h2>Facture {{ $u === \App\Shared\Enums\Utility::Electricity ? 'SBEE (électricité)' : 'SONEB (eau)' }}</h2>
        <table class="lines">
            <tr><td colspan="2" class="small">Mode de calcul et prix : {{ $block['priceLabel'] }}</td></tr>
            <tr><td>Montant de la facture principale</td><td class="num">{{ $block['bill'] === null ? 'non renseigné' : $fcfa($block['bill']) }}</td></tr>
            @if ($block['countingGap'] !== null)
                <tr><td>Consommation du compteur principal</td><td class="num">{{ $qty($block['totalConsumption'] + $block['countingGap']) }} {{ $u->unit() }}</td></tr>
            @endif
            <tr><td>Somme des décompteurs</td><td class="num">{{ $qty($block['totalConsumption']) }} {{ $u->unit() }}</td></tr>
            @if ($block['countingGap'] !== null)
                <tr><td>Écart de comptage (information)</td><td class="num">{{ $qty($block['countingGap']) }} {{ $u->unit() }} ({{ $block['countingGapPct'] }} %)</td></tr>
            @endif
            <tr><td>Participations des locataires</td><td class="num">{{ $fcfa($block['tenantsTotal']) }}</td></tr>
            @if ($block['vacantTotal'] > 0)
                <tr><td>Part du propriétaire (logements vacants)</td><td class="num">{{ $fcfa($block['vacantTotal']) }}</td></tr>
            @endif
            @if ($block['ownerRemainder'] !== null)
                <tr class="strong"><td>Reste à la charge du propriétaire (mode 2, prix fixé plafonné)</td><td class="num">{{ $fcfa($block['ownerRemainder']) }}</td></tr>
            @endif
            @if ($block['collectionGap'] !== null)
                <tr class="strong"><td>Écart entre participations et facture (mode 2 sans plafond)</td><td class="num">{{ $signed($block['collectionGap']) }}</td></tr>
            @endif
        </table>
    @endforeach

    @if ($payments !== [])
        <h2>Encaissements au {{ now()->timezone(config('app.timezone'))->format('d/m/Y') }} · date limite {{ $dueDate->format('d/m/Y') }}</h2>
        <table class="grid">
            <tr><th>Logement</th><th>Locataire</th><th class="num">Dû</th><th class="num">Payé</th><th class="num">Reste</th></tr>
            @foreach ($payments as $p)
                <tr>
                    <td>{{ $p['unit'] }}</td>
                    <td>{{ $p['name'] }}@if ($p['refundDue'] > 0) <span class="tag">À REMBOURSER {{ $num($p['refundDue']) }}</span>@endif</td>
                    <td class="num">{{ $num($p['due']) }}</td>
                    <td class="num">{{ $num($p['paid']) }}</td>
                    <td class="num">{{ $p['remaining'] > 0 ? $num($p['remaining']) : 'soldé' }}</td>
                </tr>
            @endforeach
            <tr class="sum">
                <td colspan="2">Total</td>
                <td class="num">{{ $num(array_sum(array_column($payments, 'due'))) }}</td>
                <td class="num">{{ $num(array_sum(array_column($payments, 'paid'))) }}</td>
                <td class="num">{{ $num(array_sum(array_column($payments, 'remaining'))) }}</td>
            </tr>
        </table>
    @endif

    <p class="footer">
        Mois clôturé le {{ $period->closed_at?->timezone(config('app.timezone'))->format('d/m/Y') }} ; montants figés à la clôture,
        arrondis au franc CFA. Document établi avec {{ config('app.name') }} pour le propriétaire.
    </p>
</body>
</html>
