<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\SubMeter;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Remplacement d'un décompteur (UC-10, EF-18), y compris compteur remis à zéro ou qui « fait
 * le tour » : relevé de dépose sur l'ancien, pose du nouveau le même jour. La consommation du
 * mois = (dépose − index précédent de l'ancien) + (fin de mois − pose du nouveau) ; le crédit
 * du locataire suit (D-12) puisqu'il est rattaché au logement, pas au décompteur.
 *
 * Le nouveau décompteur n'a pas de ligne « installation » dans meter_readings : sa pose
 * (installed_on, install_index) ouvre sa chaîne de relevés (H-50).
 */
final class ReplaceSubMeter
{
    public function __construct(
        private readonly OpenBillingPeriod $openBillingPeriod,
        private readonly AssertReadingFitsChain $assertReading,
    ) {}

    /**
     * @param string $removalIndex index de dépose de l'ancien, « 1300.000 »
     * @param string $installIndex index de pose du nouveau, « 0.000 »
     *
     * @throws OccupancyRejectedException|ReadingRejectedException|PeriodClosedException
     */
    public function execute(SubMeter $old, CarbonImmutable $date, string $removalIndex, string $installIndex, ?string $serialNumber = null): SubMeter
    {
        $date = $date->startOfDay();

        return DB::transaction(function () use ($old, $date, $removalIndex, $installIndex, $serialNumber): SubMeter {
            $old = SubMeter::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
            $unit = $old->unit()->with('house')->firstOrFail();

            if (! $old->isActive()) {
                throw new OccupancyRejectedException('Ce décompteur a déjà été remplacé.');
            }

            if ($date->isAfter(CarbonImmutable::today())) {
                throw new OccupancyRejectedException('La date du remplacement ne peut pas être dans le futur.');
            }

            $removalKey = (new Reading(ReadingKind::Removal, $date, 0))->sortKey();
            foreach ($old->readingChain() as $reading) {
                if (strcmp($reading->sortKey(), $removalKey) > 0) {
                    throw new OccupancyRejectedException(sprintf(
                        'L\'ancien décompteur a déjà un relevé le %s : le remplacement doit être daté après.',
                        $reading->readOn->format('d/m/Y'),
                    ));
                }
            }

            $period = $this->openBillingPeriod->execute($unit->house, $date);
            $removal = Milli::fromDecimal($removalIndex);
            $this->assertReading->assert($old, ReadingKind::Removal, $date, $removal);

            $old->readings()->create([
                'billing_period_id' => $period->id,
                'kind' => ReadingKind::Removal,
                'read_on' => $date,
                'index_value' => Milli::toDecimal($removal),
            ]);

            // Dépose d'abord : la clé « un seul décompteur actif » libère la place du nouveau.
            $old->forceFill(['removed_on' => $date, 'removal_index' => Milli::toDecimal($removal)])->save();

            return $unit->subMeters()->create([
                'utility' => $old->utility,
                'serial_number' => filled($serialNumber) ? trim($serialNumber) : null,
                'installed_on' => $date,
                'install_index' => Milli::toDecimal(Milli::fromDecimal($installIndex)),
                'replaces_sub_meter_id' => $old->id,
            ]);
        });
    }
}
