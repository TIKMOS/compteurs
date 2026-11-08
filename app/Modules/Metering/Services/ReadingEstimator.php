<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Exceptions\EstimationImpossibleException;
use App\Shared\Support\Milli;
use DateTimeImmutable;

/**
 * Estimation d'un relevé de fin de mois manquant (EF-22, EF-25, D-7, D-8). Classe pure.
 *
 * index estimé = dernier index connu + moyenne × jours restants ÷ jours du mois, où la moyenne porte
 * sur les consommations facturées des 3 dernières périodes clôturées (moins si l'historique est court).
 */
final class ReadingEstimator
{
    public const int HISTORY_MONTHS = 3;

    /**
     * @param list<int> $billedHistory consommations facturées des périodes clôturées, de la plus ancienne à la plus récente (millièmes)
     */
    public function estimate(int $lastIndex, DateTimeImmutable $lastReadOn, DateTimeImmutable $monthEnd, array $billedHistory): int
    {
        return $lastIndex + $this->estimatedConsumption($lastReadOn, $monthEnd, $billedHistory);
    }

    /**
     * @param list<int> $billedHistory
     */
    public function estimatedConsumption(DateTimeImmutable $lastReadOn, DateTimeImmutable $monthEnd, array $billedHistory): int
    {
        $history = array_slice($billedHistory, -self::HISTORY_MONTHS);
        if ($history === []) {
            throw EstimationImpossibleException::noHistory();
        }

        $days = (int) $lastReadOn->setTime(0, 0)->diff($monthEnd->setTime(0, 0))->days;
        $daysInMonth = (int) $monthEnd->format('t');

        return Milli::roundHalfUp(array_sum($history) * $days, count($history) * $daysInMonth);
    }

    /**
     * Base de calcul affichée avec la mention « estimée » (EF-23), ex. « moyenne de 150, 134, 142 ».
     *
     * @param list<int> $billedHistory
     */
    public function basis(array $billedHistory): string
    {
        $history = array_slice($billedHistory, -self::HISTORY_MONTHS);

        return 'moyenne de ' . implode(', ', array_map(Milli::format(...), $history));
    }
}
