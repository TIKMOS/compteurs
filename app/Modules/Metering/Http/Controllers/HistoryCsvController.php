<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\Charge;
use App\Models\House;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Support\Milli;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV de l'historique d'une maison (lot 2) : une ligne par participation figée des mois
 * clôturés. Lisible directement par Excel en français : UTF-8 avec BOM, séparateur « ; »,
 * décimales à virgule.
 */
final class HistoryCsvController
{
    public const HEADERS = [
        'Mois', 'Logement', 'Payeur', 'Fluide', 'Unité', 'Consommation brute', 'Crédit déduit',
        'Consommation retenue', 'Prix appliqué (FCFA/unité)', 'Origine du prix', 'Participation (FCFA)', 'Estimée', 'Régularisation',
    ];

    public function __invoke(House $house): StreamedResponse
    {
        Gate::authorize('view', $house);

        $charges = Charge::query()
            ->whereHas('utilityBill.billingPeriod', fn ($q) => $q->where('house_id', $house->id)->where('status', PeriodStatus::Closed))
            ->with(['utilityBill.billingPeriod', 'unit', 'occupancy.tenant'])
            ->get()
            ->sortBy(fn (Charge $c): string => $c->utilityBill->billingPeriod->month->format('Y-m') . '|' . $c->utilityBill->utility->value . '|' . mb_strtolower($c->unit->label));

        $fileName = Str::slug('historique ' . $house->name) . '.csv';

        return response()->streamDownload(function () use ($charges): void {
            $out = fopen('php://output', 'wb') ?: throw new RuntimeException('Sortie de l\'export indisponible.');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, self::HEADERS, ';', '"', '');

            foreach ($charges as $charge) {
                $bill = $charge->utilityBill;
                $price = ResolvedPrice::fromBill($bill);

                fputcsv($out, [
                    $bill->billingPeriod->month->format('Y-m'),
                    $charge->unit->label,
                    $charge->occupancy?->tenant->name ?? 'Propriétaire / vacant',
                    Str::ucfirst($bill->utility->label()),
                    $bill->utility->unit(),
                    self::decimal($charge->raw_consumption),
                    self::decimal($charge->credit_applied),
                    self::decimal($charge->consumption),
                    $price === null ? '' : str_replace("\u{00A0}", '', ResolvedPrice::format($price->applied)),
                    $price === null ? '' : ($price->source->value === 'fixed' ? 'prix fixé' : 'prix réel'),
                    $charge->amount,
                    $charge->is_estimated ? 'oui' : 'non',
                    $charge->includes_regularization ? 'oui' : 'non',
                ], ';', '"', '');
            }

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * « 142.000 » → « 142 » ; « 199.500 » → « 199,5 » (sans séparateur de milliers pour Excel).
     */
    private static function decimal(string $value): string
    {
        // Le signe moins typographique de l'écran (« − ») n'est pas lu comme un nombre par Excel.
        return str_replace(["\u{00A0}", '−'], ['', '-'], Milli::format(Milli::fromDecimal($value)));
    }
}
