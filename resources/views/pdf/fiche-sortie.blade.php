{{-- Fiche de sortie d'un locataire (D-11, D-14, D-15), rendue par DomPDF. Noir et blanc, une colonne. --}}
@php
    /** @var \App\Modules\Metering\Data\ExitStatement $x */
    $fcfa = fn (int $a): string => \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
    $ucfirst = fn (string $t): string => \Illuminate\Support\Str::ucfirst($t);
    $occ = $x->occupancy;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche de sortie — {{ $occ->tenant->name }}</title>
    @include('pdf.partials.styles')
</head>
<body>
    <h1>Fiche de sortie du locataire</h1>
    <p class="subtitle">Participation aux frais d'eau et d'électricité · solde de fin d'occupation</p>

    <table class="info">
        <tr><th>Maison</th><td>{{ $x->house->name }}@if ($x->house->address) — {{ $x->house->address }}@endif</td></tr>
        <tr><th>Logement</th><td>{{ $x->unit->label }}</td></tr>
        <tr><th>Locataire</th><td><strong>{{ $occ->tenant->name }}</strong>@if ($occ->tenant->phone) · {{ $occ->tenant->displayPhone() }}@endif</td></tr>
        <tr><th>Occupation</th><td>du {{ $occ->started_on->format('d/m/Y') }} au {{ $occ->ended_on->format('d/m/Y') }}</td></tr>
    </table>

    <h2>Dernier relevé</h2>
    <table class="lines">
        <tr><th>Fluide</th><th>Date</th><th class="num">Index de sortie</th></tr>
        @forelse ($x->readings as $r)
            <tr>
                <td>{{ $ucfirst($r['utility']->label()) }}@if ($r['estimated']) <span class="tag">ESTIMÉ</span>@endif</td>
                <td>{{ $r['readOn']->format('d/m/Y') }}</td>
                <td class="num">{{ $qty($r['index'], $r['utility']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Aucun décompteur relevé à la sortie.</td></tr>
        @endforelse
    </table>

    <h2>Crédit de consommation converti en FCFA</h2>
    @if ($x->lots === [])
        <p>Aucun crédit de consommation restant à la sortie.</p>
    @else
        <table class="lines">
            <tr><th>Fluide</th><th>Origine</th><th class="num">Quantité</th><th class="num">Prix (FCFA)</th><th class="num">Valeur (FCFA)</th></tr>
            @foreach ($x->lots as $lot)
                <tr>
                    <td>{{ $ucfirst($lot['utility']->label()) }}</td>
                    <td>estimation de {{ $lot['origin'] }}</td>
                    <td class="num">{{ $qty($lot['quantity'], $lot['utility']) }}</td>
                    <td class="num">{{ $lot['unitPrice'] }}</td>
                    <td class="num">{{ $lot['value'] }}</td>
                </tr>
            @endforeach
            <tr class="strong"><td colspan="4">Valeur du crédit (arrondie une seule fois au franc)</td><td class="num">{{ $fcfa($x->creditValue) }}</td></tr>
        </table>
        <p class="note">Chaque quantité est valorisée au prix unitaire appliqué le mois où elle a été surestimée.</p>
    @endif

    <table class="totals">
        <tr><td>Dernière fiche ({{ $x->period->label() }})</td><td class="num">{{ $fcfa($x->lastStatementTotal) }}</td></tr>
        @if ($x->creditValue > 0)
            <tr><td>Crédit déduit de la dernière fiche</td><td class="num">− {{ $fcfa($x->deducted) }}</td></tr>
        @endif
        <tr class="strong"><td>Net de la dernière fiche</td><td class="num">{{ $fcfa($x->lastNet()) }}</td></tr>
        <tr><td>Déjà payé au {{ now()->timezone(config('app.timezone'))->format('d/m/Y') }}</td><td class="num">{{ $fcfa($x->paid) }}</td></tr>
        <tr class="strong"><td>Reste à payer par le locataire</td><td class="num">{{ $fcfa($x->remaining()) }}</td></tr>
        @if ($x->refundDue > 0)
            <tr class="strong big boxed"><td>À rembourser au locataire</td><td class="num">{{ $fcfa($x->refundDue) }}</td></tr>
            <tr><td colspan="2" class="words">Soit {{ \App\Modules\Metering\Data\TenantStatement::inWords($x->refundDue) }}, crédit non utilisé.</td></tr>
        @endif
    </table>

    <p class="footer">
        Le crédit de consommation appartient au locataire et ne passe jamais au locataire suivant.
        Le solde est établi à la clôture de {{ $x->period->label() }}
        ({{ $x->period->closed_at?->timezone(config('app.timezone'))->format('d/m/Y') }}), avec {{ config('app.name') }}.
        Ce document n'est pas une facture.
    </p>

    <table style="margin-top: 22pt;">
        <tr>
            <td style="width: 50%;">Signature du propriétaire</td>
            <td>Signature du locataire</td>
        </tr>
        <tr><td style="height: 40pt;"></td><td></td></tr>
    </table>
</body>
</html>
