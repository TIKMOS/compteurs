{{-- Fiche PDF d'un locataire (EF-36, EF-37), rendue par DomPDF : CSS simple, en ligne,
     police intégrée DejaVu Sans (accents, espace insécable). --}}
@php
    /** @var \App\Modules\Metering\Data\TenantStatement $s */
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Participation aux frais — {{ $s->monthLabel() }}</title>
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10.5pt; color: #222; margin: 0; }
        h1 { font-size: 15pt; margin: 0 0 2pt; color: #1f2a8a; }
        h2 { font-size: 11.5pt; margin: 14pt 0 4pt; color: #1f2a8a; }
        .muted { color: #666; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4pt 6pt; border-bottom: 0.5pt solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f4f1ea; font-size: 9pt; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1pt solid #222; border-bottom: none; }
        .badge { display: inline-block; padding: 0 4pt; border: 0.5pt solid #b7791f; color: #8a5a00; font-size: 8pt; }
        .box { border: 0.5pt solid #ccc; padding: 6pt 8pt; margin-top: 10pt; }
        .due { color: #9b1c1c; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Participation aux frais d'eau et d'électricité</h1>
    <p class="muted">{{ \Illuminate\Support\Str::ucfirst($s->monthLabel()) }} · {{ $s->house->name }}@if ($s->house->address) — {{ $s->house->address }}@endif</p>

    <table style="margin-top: 8pt;">
        <tr><td style="width: 35%;">Locataire</td><td><strong>{{ $s->occupancy->tenant->name }}</strong>@if ($s->occupancy->tenant->phone) · {{ $s->occupancy->tenant->displayPhone() }}@endif</td></tr>
        <tr><td>Logement</td><td>{{ $s->unit->label }}</td></tr>
        <tr><td>Période d'occupation dans le mois</td><td>du {{ $s->from->format('d/m/Y') }} au {{ $s->to->format('d/m/Y') }}</td></tr>
    </table>

    @foreach ($s->utilities as $u)
        <h2>{{ \Illuminate\Support\Str::ucfirst($u['utility']->label()) }}
            @if ($u['estimated']) <span class="badge">estimée</span> @endif
            @if ($u['regularization']) <span class="badge">régularisation</span> @endif
        </h2>
        <table>
            <tr><th>Index de début</th><th>Index de fin</th><th class="num">Consommation</th></tr>
            @foreach ($u['segments'] as $segment)
                <tr><td>{{ $segment['from'] }}</td><td>{{ $segment['to'] }}</td><td class="num">{{ $qty($segment['consumption'], $u['utility']) }}</td></tr>
            @endforeach
            @if ($u['creditApplied'] > 0)
                <tr><td colspan="2">Crédit de consommation déduit</td><td class="num">−{{ $qty($u['creditApplied'], $u['utility']) }}</td></tr>
            @endif
            <tr><td colspan="2">Consommation retenue</td><td class="num">{{ $qty($u['consumption'], $u['utility']) }}</td></tr>
            <tr><td colspan="2">{{ $u['priceLabel'] }}</td><td></td></tr>
            <tr class="total"><td colspan="2">Participation</td><td class="num">{{ $fcfa($u['amount']) }}</td></tr>
        </table>
        @if ($u['estimationBasis'])
            <p class="muted">Index de fin estimé ({{ $u['estimationBasis'] }}) : il sera régularisé au prochain relevé réel.</p>
        @endif
        @if ($u['creditBalance'] > 0)
            <p class="muted">Crédit restant : {{ $qty($u['creditBalance'], $u['utility']) }}</p>
        @endif
    @endforeach

    <div class="box">
        <table>
            <tr class="total"><td>Total du mois</td><td class="num">{{ $fcfa($s->total) }}</td></tr>
            @if ($s->creditRefunded !== null)
                <tr><td>Crédit restant remboursé</td><td class="num">−{{ $fcfa($s->creditRefunded) }}</td></tr>
                <tr class="total"><td>Net à payer</td><td class="num">{{ $fcfa($s->net()) }}</td></tr>
                @if ($s->refundDue > 0)
                    <tr><td class="due">À rembourser au locataire</td><td class="num due">{{ $fcfa($s->refundDue) }}</td></tr>
                @endif
            @endif
        </table>
    </div>

    <p class="muted" style="margin-top: 14pt;">
        Répartition des charges établie avec {{ config('app.name') }} le {{ $s->period->closed_at?->timezone(config('app.timezone'))->format('d/m/Y') }}.
        Montants arrondis au franc CFA.
    </p>
</body>
</html>
