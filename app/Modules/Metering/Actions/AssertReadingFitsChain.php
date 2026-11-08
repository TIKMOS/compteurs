<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\SubMeter;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Services\ReadingValidator;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;

/**
 * Vérifie qu'un nouveau relevé s'insère dans la chaîne des index du décompteur
 * (EF-17, CONCEPTION.md § 4.3) : pas avant la pose, pas sous le relevé précédent
 * (sauf après une estimation, ReadingValidator), pas au-dessus du relevé réel suivant.
 */
final class AssertReadingFitsChain
{
    public function __construct(private readonly ReadingValidator $validator) {}

    /**
     * @throws ReadingRejectedException
     */
    public function assert(SubMeter $meter, ReadingKind $kind, CarbonImmutable $readOn, int $index, ?int $ignoreReadingId = null): void
    {
        $utility = $meter->utility;

        if ($readOn->lessThan($meter->installed_on)) {
            throw new ReadingRejectedException($utility, sprintf(
                'Le décompteur d\'%s a été posé le %s : la date doit être postérieure.',
                $utility->label(),
                $meter->installed_on->format('d/m/Y'),
            ));
        }

        $new = new Reading($kind, $readOn, $index);
        $previous = null;
        $next = null;
        $lastReal = 0;

        // Le relevé corrigé lui-même ne compte pas (ex. index de fin de mois ressaisi).
        foreach ($meter->readingChain($ignoreReadingId) as $reading) {
            if (strcmp($reading->sortKey(), $new->sortKey()) <= 0) {
                $previous = $reading;
                if (! $reading->isEstimated) {
                    $lastReal = $reading->index;
                }
            } elseif ($next === null) {
                $next = $reading;
            }
        }

        try {
            if ($previous !== null) {
                $this->validator->assertAcceptable($previous, $index, $lastReal);
            }
        } catch (IndexLowerThanPreviousException $e) {
            throw new ReadingRejectedException($utility, $e->getMessage());
        }

        if ($next !== null && ! $next->isEstimated && $index > $next->index) {
            throw new ReadingRejectedException($utility, sprintf(
                "L'index saisi (%s) dépasse celui du relevé suivant (%s, le %s).",
                Milli::format($index),
                Milli::format($next->index),
                $next->readOn->format('d/m/Y'),
            ));
        }
    }
}
