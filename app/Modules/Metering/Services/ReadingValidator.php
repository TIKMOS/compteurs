<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;

/**
 * Contrôle d'un index saisi par rapport au relevé précédent du décompteur (EF-17). Classe pure.
 */
final class ReadingValidator
{
    /**
     * @param int $lastRealIndex dernier index réellement lu sur ce décompteur (millièmes)
     */
    public function assertAcceptable(Reading $previous, int $newIndex, int $lastRealIndex): void
    {
        if ($newIndex >= $previous->index) {
            return;
        }

        if (! $previous->isEstimated) {
            throw IndexLowerThanPreviousException::belowPrevious($newIndex, $previous->index);
        }

        if ($newIndex < $lastRealIndex) {
            throw IndexLowerThanPreviousException::belowLastReal($newIndex, $lastRealIndex);
        }
    }
}
