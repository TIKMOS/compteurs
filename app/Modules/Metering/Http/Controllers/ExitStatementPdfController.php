<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\House;
use App\Models\Occupancy;
use App\Modules\Metering\Queries\BuildExitStatement;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Fiche de sortie PDF d'un locataire (D-11, D-14, D-15), une fois le mois de sortie clôturé.
 */
final class ExitStatementPdfController
{
    public function __invoke(House $house, Occupancy $occupancy, BuildExitStatement $build): PdfBuilder
    {
        Gate::authorize('view', $house);
        abort_if($occupancy->unit->house_id !== $house->id, 404);

        $statement = $build->execute($occupancy);
        abort_if($statement === null, 404);

        return Pdf::view('pdf.fiche-sortie', ['x' => $statement])
            ->driver('dompdf')
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name($statement->fileName());
    }
}
