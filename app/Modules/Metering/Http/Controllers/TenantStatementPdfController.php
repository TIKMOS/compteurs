<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\House;
use App\Models\Occupancy;
use App\Modules\Metering\Queries\BuildTenantStatement;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Fiche PDF d'un locataire pour un mois clôturé (EF-37), générée avec DomPDF : fonctionne sur
 * l'hébergement mutualisé, sans navigateur ni Node (docs/STACK.md).
 */
final class TenantStatementPdfController
{
    public function __invoke(House $house, string $mois, Occupancy $occupancy, BuildTenantStatement $build): PdfBuilder
    {
        Gate::authorize('view', $house);

        $period = $house->billingPeriods()->where('month', $mois . '-01')->first();
        $unit = $occupancy->unit;

        abort_if($period === null || ! $period->isClosed() || $unit->house_id !== $house->id, 404);

        $statement = $build->execute($period, $occupancy);
        abort_if($statement->utilities === [], 404);

        return Pdf::view('pdf.fiche-locataire', ['s' => $statement])
            ->driver('dompdf')
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name($statement->fileName());
    }
}
