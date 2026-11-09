{{-- Fiche mensuelle d'un locataire (EF-36, EF-37), rendue par DomPDF.
     Lisible imprimée en noir et blanc sur A4 et à l'écran d'un téléphone : une seule colonne,
     aucune information portée par la couleur seule, police DejaVu Sans (accents, espace insécable). --}}
@php
    /** @var \App\Modules\Metering\Data\TenantStatement $s */
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
    $ucfirst = fn (string $t): string => \Illuminate\Support\Str::ucfirst($t);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Participation aux frais — {{ $s->monthLabel() }}</title>
    @include('pdf.partials.styles')
</head>
<body>
    <h1>Participation aux frais d'eau et d'électricité</h1>
    <p class="subtitle">{{ $ucfirst($s->monthLabel()) }}</p>

    <table class="info">
        <tr><th>Maison</th><td>{{ $s->house->name }}@if ($s->house->address) — {{ $s->house->address }}@endif</td></tr>
        <tr><th>Logement</th><td>{{ $s->unit->label }}</td></tr>
        <tr><th>Locataire</th><td><strong>{{ $s->occupancy->tenant->name }}</strong>@if ($s->occupancy->tenant->phone) · {{ $s->occupancy->tenant->displayPhone() }}@endif</td></tr>
        <tr><th>Mois</th><td>{{ $ucfirst($s->monthLabel()) }} (du {{ $s->from->format('d/m/Y') }} au {{ $s->to->format('d/m/Y') }})</td></tr>
    </table>

    @foreach ($s->utilities as $u)
        <h2>
            {{ $ucfirst($u['utility']->label()) }}
            @if ($u['estimated']) <span class="tag">ESTIMÉ</span> @endif
            @if ($u['regularization']) <span class="tag">RÉGULARISATION</span> @endif
        </h2>
        <table class="lines">
            <tr><th>Index ancien</th><th>Index nouveau</th><th class="num">Consommation</th></tr>
            @foreach ($u['segments'] as $segment)
                <tr><td>{{ $segment['from'] }}</td><td>{{ $segment['to'] }}</td><td class="num">{{ $qty($segment['consumption'], $u['utility']) }}</td></tr>
            @endforeach
            @if ($u['creditApplied'] > 0)
                <tr><td colspan="2">Crédit de consommation déduit</td><td class="num">− {{ $qty($u['creditApplied'], $u['utility']) }}</td></tr>
            @endif
            <tr><td colspan="2">Consommation retenue</td><td class="num">{{ $qty($u['consumption'], $u['utility']) }}</td></tr>
            <tr><td colspan="2">Crédit de consommation restant</td><td class="num">{{ $qty($u['creditBalance'], $u['utility']) }}</td></tr>
            <tr><td colspan="3" class="small">Mode de calcul et prix : {{ $u['priceLabel'] }}</td></tr>
            <tr class="strong"><td colspan="2">Participation {{ \App\Modules\Metering\Queries\ComputeBillingPeriod::of($u['utility']->label()) }}</td><td class="num">{{ $fcfa($u['amount']) }}</td></tr>
        </table>
        @if ($u['estimated'])
            <p class="note">
                Index nouveau estimé{{ $u['estimationBasis'] ? ' ('.$u['estimationBasis'].')' : '' }}, faute de relevé réel :
                l'écart sera régularisé au prochain relevé réel.
            </p>
        @endif
    @endforeach

    <table class="totals">
        <tr class="strong"><td>Total du mois</td><td class="num">{{ $fcfa($s->total) }}</td></tr>
        @if ($s->creditRefunded !== null)
            <tr><td>Crédit restant remboursé (sortie)</td><td class="num">− {{ $fcfa($s->creditRefunded) }}</td></tr>
            <tr class="strong"><td>Net à payer</td><td class="num">{{ $fcfa($s->net()) }}</td></tr>
        @endif
        <tr><td colspan="2" class="words">Soit {{ \App\Modules\Metering\Data\TenantStatement::inWords($s->net()) }}.</td></tr>
        <tr><td>Déjà payé au {{ now()->timezone(config('app.timezone'))->format('d/m/Y') }}</td><td class="num">{{ $fcfa($s->paid) }}</td></tr>
        <tr class="strong big"><td>Reste à payer</td><td class="num">{{ $fcfa($s->remaining()) }}</td></tr>
        @if ($s->dueDate && $s->remaining() > 0)
            <tr><td>Date limite de paiement</td><td class="num"><strong>{{ $s->dueDate->format('d/m/Y') }}</strong></td></tr>
        @endif
        @if ($s->refundDue > 0)
            <tr class="strong boxed"><td>À rembourser au locataire</td><td class="num">{{ $fcfa($s->refundDue) }}</td></tr>
        @endif
    </table>

    <p class="footer">
        Répartition des charges entre les occupants, établie avec {{ config('app.name') }} et clôturée le
        {{ $s->period->closed_at?->timezone(config('app.timezone'))->format('d/m/Y') }}.
        Montants arrondis au franc CFA. Décompte de répartition des charges entre occupants.
    </p>
</body>
</html>
