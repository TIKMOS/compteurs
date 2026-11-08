<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\OccupancySpan;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Data\Segment;
use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;
use App\Shared\Enums\ReadingKind;

/**
 * Forme les tranches d'un décompteur pour une période et les attribue (EF-11). Classe pure.
 */
final class SegmentBuilder
{
    /**
     * @param list<Reading> $readings relevés du décompteur, y compris le point de départ
     *                                (fin de mois précédent, relevé initial ou pose)
     * @param list<OccupancySpan> $occupancies occupations du logement
     *
     * @return list<Segment>
     */
    public function build(array $readings, array $occupancies): array
    {
        usort($readings, static fn (Reading $a, Reading $b): int => $a->sortKey() <=> $b->sortKey());

        $segments = [];
        for ($i = 1, $n = count($readings); $i < $n; $i++) {
            $start = $readings[$i - 1];
            $end = $readings[$i];

            // Un index qui baisse n'est admis qu'après un relevé estimé (EF-17) ; il crée alors du crédit.
            if ($end->index < $start->index && ! $start->isEstimated) {
                throw IndexLowerThanPreviousException::belowPrevious($end->index, $start->index);
            }

            $segments[] = new Segment($start, $end, $this->payer($start, $end, $occupancies));
        }

        return $segments;
    }

    /**
     * @param list<OccupancySpan> $occupancies
     */
    private function payer(Reading $a, Reading $b, array $occupancies): ?int
    {
        if ($b->kind === ReadingKind::Exit) {
            return $b->occupancyId;
        }
        if ($a->kind === ReadingKind::Entry) {
            return $a->occupancyId;
        }
        if ($a->kind === ReadingKind::Exit || $b->kind === ReadingKind::Entry) {
            return null;
        }

        foreach ($occupancies as $occupancy) {
            if ($occupancy->isActiveOn($b->readOn)) {
                return $occupancy->id;
            }
        }

        return null;
    }
}
