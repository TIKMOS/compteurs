<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\Occupancy;
use App\Models\Unit;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Fiche de sortie d'un locataire (D-11, D-14, D-15) : derniers index, crédit converti en FCFA,
 * déduction de la dernière fiche et éventuel montant à rembourser au locataire.
 */
final readonly class ExitStatement
{
    /**
     * @param list<array{utility: Utility, index: int, readOn: CarbonImmutable, estimated: bool}> $readings
     * @param list<array{utility: Utility, quantity: int, unitPrice: string, origin: string, value: string}> $lots valeurs exactes avant l'arrondi unique
     */
    public function __construct(
        public BillingPeriod $period,
        public House $house,
        public Unit $unit,
        public Occupancy $occupancy,
        public array $readings,
        public array $lots,
        public int $creditValue,
        public int $lastStatementTotal,
        public int $deducted,
        public int $refundDue,
        public int $paid,
    ) {}

    /**
     * Net de la dernière fiche après déduction du crédit.
     */
    public function lastNet(): int
    {
        return $this->lastStatementTotal - $this->deducted;
    }

    public function remaining(): int
    {
        return max(0, $this->lastNet() - $this->paid);
    }

    public function fileName(): string
    {
        return Str::slug('fiche de sortie ' . $this->unit->label . ' ' . $this->occupancy->tenant->name . ' ' . $this->occupancy->ended_on->format('Y-m-d')) . '.pdf';
    }
}
