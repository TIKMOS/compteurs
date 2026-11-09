<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\House;
use App\Models\Occupancy;
use App\Modules\Metering\Livewire\PeriodSummary;
use App\Modules\Metering\Queries\TenantBalance;
use App\Shared\Enums\Utility;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Récapitulatif PDF d'un mois clôturé pour le propriétaire : tableau de tous les logements
 * (consommations, participations, encaissements), totaux, rappel de chaque facture principale,
 * écart de comptage et, en mode 2, reste à charge ou écart d'encaissement.
 */
final class MonthSummaryPdfController
{
    public function __invoke(House $house, string $mois): PdfBuilder
    {
        Gate::authorize('view', $house);

        $period = $house->billingPeriods()->where('month', $mois . '-01')->first();
        abort_if($period === null || ! $period->isClosed(), 404);

        $summary = PeriodSummary::fromClosed($period);

        // Une ligne par logement et par payeur (deux lignes si changement de locataire dans le mois).
        $rows = [];
        foreach ($summary->utilities as $block) {
            foreach ($block['lines'] as $line) {
                $key = $line['unitLabel'] . '|' . ($line['occupancyId'] ?? 'vacant');
                $rows[$key] ??= [
                    'unit' => $line['unitLabel'],
                    'payer' => $line['payer'],
                    'occupancyId' => $line['occupancyId'],
                    'utilities' => [],
                    'estimated' => false,
                    'total' => 0,
                ];
                $rows[$key]['utilities'][$block['utility']->value] = ['consumption' => $line['consumption'], 'amount' => $line['amount']];
                $rows[$key]['estimated'] = $rows[$key]['estimated'] || $line['estimated'];
                $rows[$key]['total'] += $line['amount'];
            }
        }
        uksort($rows, 'strnatcasecmp');

        $payments = [];
        foreach ($summary->tenants as $tenant) {
            $occupancy = Occupancy::query()->find($tenant['occupancyId']);
            $paid = TenantBalance::paid($occupancy, $period);
            $payments[] = [
                'unit' => $tenant['unitLabel'],
                'name' => $tenant['name'],
                'due' => $tenant['net'],
                'paid' => $paid,
                'remaining' => max(0, $tenant['net'] - $paid),
                'refundDue' => $tenant['settlement']['refundDue'] ?? 0,
            ];
        }

        return Pdf::view('pdf.recapitulatif-mois', [
            'house' => $house,
            'period' => $period,
            'summary' => $summary,
            'rows' => array_values($rows),
            'payments' => $payments,
            'utilities' => array_values(array_filter(Utility::cases(), fn (Utility $u): bool => collect($summary->utilities)->contains('utility', $u))),
            'dueDate' => $house->paymentDueDate($period),
        ])
            ->driver('dompdf')
            ->format('a4')
            ->margins(12, 12, 12, 12)
            ->name(Str::slug('recapitulatif ' . $mois . ' ' . $house->name) . '.pdf');
    }
}
