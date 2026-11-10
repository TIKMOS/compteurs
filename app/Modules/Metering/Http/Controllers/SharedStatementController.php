<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\Occupancy;
use App\Modules\Metering\Queries\BuildTenantStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Fiche PDF ouverte depuis le lien envoyé par WhatsApp ou SMS : lien signé et temporaire
 * (TenantStatement::SHARE_DAYS jours), sans compte. Expiré ou modifié : page d'explication, 403.
 */
final class SharedStatementController
{
    public function __invoke(Request $request, Occupancy $occupancy, string $mois, BuildTenantStatement $build): PdfBuilder|Response
    {
        if (! $request->hasValidSignature()) {
            return response()->view('pages.lien-expire', status: 403);
        }

        $unit = $occupancy->unit;
        $period = $unit->house->billingPeriods()->where('month', $mois . '-01')->first();
        abort_if($period === null || ! $period->isClosed(), 404);

        $statement = $build->execute($period, $occupancy);
        abort_if($statement->utilities === [], 404);

        return Pdf::view('pdf.fiche-locataire', ['s' => $statement])
            ->driver('dompdf')
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name($statement->fileName());
    }
}
