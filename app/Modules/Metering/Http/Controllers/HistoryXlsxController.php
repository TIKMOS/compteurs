<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\House;
use App\Models\Payment;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\PriceSource;
use App\Shared\Enums\PricingMode;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export Excel (.xlsx) de l'historique d'une maison sur une période choisie (mois clôturés) :
 * participations, factures, encaissements. OpenSpout, pur PHP : fonctionne sur l'hébergement
 * mutualisé (H-77). Paramètres « du » et « au » au format AAAA-MM ; par défaut, les 12 derniers mois.
 */
final class HistoryXlsxController
{
    public function __invoke(Request $request, House $house): BinaryFileResponse
    {
        Gate::authorize('view', $house);

        [$from, $to] = $this->range($request);

        $periods = BillingPeriod::query()
            ->where('house_id', $house->id)
            ->where('status', PeriodStatus::Closed)
            ->whereBetween('month', [$from->toDateString(), $to->toDateString()])
            ->orderBy('month')
            ->with(['bills.charges.unit', 'bills.charges.occupancy.tenant', 'creditSettlements'])
            ->get();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $fcfa = (new Style)->setFormat('#,##0');
        $qty = (new Style)->setFormat('#,##0.###');
        $price = (new Style)->setFormat('#,##0.000');

        // Feuille 1 : une ligne par participation figée.
        $writer->getCurrentSheet()->setName('Participations');
        $writer->addRow(Row::fromValues([
            'Mois', 'Logement', 'Payeur', 'Fluide', 'Unité', 'Consommation brute', 'Crédit déduit', 'Consommation retenue',
            'Prix appliqué (FCFA/unité)', 'Origine du prix', 'Participation (FCFA)', 'Estimée', 'Régularisation',
        ], $bold));
        foreach ($periods as $period) {
            foreach ($period->bills->sortBy(fn ($b) => $b->utility->value) as $bill) {
                $resolved = ResolvedPrice::fromBill($bill);
                foreach ($bill->charges->sortBy(fn (Charge $c): string => mb_strtolower($c->unit->label)) as $charge) {
                    $writer->addRow(Row::fromValuesWithStyles([
                        $period->month->format('Y-m'),
                        $charge->unit->label,
                        $charge->occupancy?->tenant->name ?? 'Propriétaire / vacant',
                        Str::ucfirst($bill->utility->label()),
                        $bill->utility->unit(),
                        self::decimal($charge->raw_consumption),
                        self::decimal($charge->credit_applied),
                        self::decimal($charge->consumption),
                        $bill->unit_price === null ? null : self::fraction($bill->unit_price),
                        $resolved === null ? '' : ($resolved->source === PriceSource::Fixed ? 'prix fixé' : 'prix réel'),
                        $charge->amount,
                        $charge->is_estimated ? 'oui' : 'non',
                        $charge->includes_regularization ? 'oui' : 'non',
                    ], null, [5 => $qty, 6 => $qty, 7 => $qty, 8 => $price, 10 => $fcfa]));
                }
            }
        }
        $writer->getCurrentSheet()->setColumnWidth(24, 2, 3);
        $writer->getCurrentSheet()->setColumnWidth(16, 6, 7, 8, 9, 11);

        // Feuille 2 : factures principales et part du propriétaire.
        $writer->addNewSheetAndMakeItCurrent()->setName('Factures');
        $writer->addRow(Row::fromValues([
            'Mois', 'Fluide', 'Mode de calcul', 'Facture (FCFA)', 'Compteur principal', 'Somme des décompteurs', 'Écart de comptage',
            'Participations des locataires (FCFA)', 'Part des logements vacants (FCFA)', 'Facture − participations (FCFA)',
        ], $bold));
        foreach ($periods as $period) {
            foreach ($period->bills->sortBy(fn ($b) => $b->utility->value) as $bill) {
                $tenants = (int) $bill->charges->whereNotNull('occupancy_id')->sum('amount');
                $vacant = (int) $bill->charges->whereNull('occupancy_id')->sum('amount');
                $main = $bill->main_consumption === null ? null : self::decimal($bill->main_consumption);
                $meters = self::decimal($bill->total_consumption ?? '0');
                $writer->addRow(Row::fromValuesWithStyles([
                    $period->month->format('Y-m'),
                    Str::ucfirst($bill->utility->label()),
                    $bill->pricing_mode === PricingMode::FixedPrice->value ? 'prix fixé' . ($bill->cap_to_actual ? ' plafonné' : ' sans plafond') : 'répartition de la facture',
                    $bill->total_amount,
                    $main,
                    $meters,
                    $main === null ? null : round($main - $meters, 3),
                    $tenants,
                    $vacant,
                    $bill->total_amount === null ? null : $bill->total_amount - $tenants - $vacant,
                ], null, [3 => $fcfa, 4 => $qty, 5 => $qty, 6 => $qty, 7 => $fcfa, 8 => $fcfa, 9 => $fcfa]));
            }
        }
        $writer->getCurrentSheet()->setColumnWidth(18, 2, 3, 4, 5, 6, 7, 8, 9, 10);

        // Feuille 3 : encaissements par locataire et par mois.
        $writer->addNewSheetAndMakeItCurrent()->setName('Encaissements');
        $writer->addRow(Row::fromValues(['Mois', 'Logement', 'Locataire', 'Dû (FCFA)', 'Payé (FCFA)', 'Reste (FCFA)', 'À rembourser (FCFA)'], $bold));
        foreach ($periods as $period) {
            $byOccupancy = $period->bills->flatMap->charges->whereNotNull('occupancy_id')->groupBy('occupancy_id');
            foreach ($byOccupancy as $occupancyId => $charges) {
                $first = $charges->first();
                $settlement = $period->creditSettlements->firstWhere('occupancy_id', $occupancyId);
                $due = (int) $charges->sum('amount') - (int) ($settlement?->deducted_amount ?? 0);
                $paid = (int) Payment::query()->where('billing_period_id', $period->id)->where('occupancy_id', $occupancyId)->sum('amount');
                $writer->addRow(Row::fromValuesWithStyles([
                    $period->month->format('Y-m'),
                    $first->unit->label,
                    $first->occupancy->tenant->name,
                    $due,
                    $paid,
                    max(0, $due - $paid),
                    (int) ($settlement?->refund_due ?? 0),
                ], null, [3 => $fcfa, 4 => $fcfa, 5 => $fcfa, 6 => $fcfa]));
            }
        }
        $writer->getCurrentSheet()->setColumnWidth(24, 2, 3);
        $writer->getCurrentSheet()->setColumnWidth(16, 4, 5, 6, 7);

        $writer->close();

        $name = Str::slug('historique ' . $house->name . ' ' . $from->format('Y-m') . ' ' . $to->format('Y-m')) . '.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * « 142.500 » → 142.5 (nombre lu comme tel par Excel).
     */
    private static function decimal(string $value): float
    {
        return Milli::fromDecimal($value) / 1000;
    }

    private static function fraction(string $value): float
    {
        $f = Fraction::parse($value);

        return round($f->numerator / $f->denominator, 3);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $request->validate([
            'du' => ['nullable', 'date_format:Y-m'],
            'au' => ['nullable', 'date_format:Y-m'],
        ], [], ['du' => 'mois de début', 'au' => 'mois de fin']);

        $to = $request->filled('au') ? CarbonImmutable::createFromFormat('!Y-m', $request->string('au')->toString()) : CarbonImmutable::now()->startOfMonth();
        $from = $request->filled('du') ? CarbonImmutable::createFromFormat('!Y-m', $request->string('du')->toString()) : $to->subMonths(11);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['du' => 'Le mois de début doit précéder le mois de fin.']);
        }

        return [$from, $to];
    }
}
