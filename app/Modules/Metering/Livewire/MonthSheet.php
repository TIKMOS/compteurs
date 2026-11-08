<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\SubMeter;
use App\Models\Unit;
use App\Modules\Metering\Data\Reading;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;

/**
 * Vue d'ensemble d'un mois d'une maison pour l'écran de saisie : par logement, les
 * décompteurs à relever, leur index de début, les relevés intermédiaires (entrées, sorties,
 * dépose) et l'index de fin saisi ou estimé. Lecture seule : les écritures passent par les Actions.
 */
final readonly class MonthSheet
{
    /**
     * @param list<array{unit: Unit, occupants: list<string>, meters: list<array<string, mixed>>}> $units
     */
    private function __construct(
        public CarbonImmutable $month,
        public ?BillingPeriod $period,
        public array $units,
    ) {}

    public static function build(House $house, CarbonImmutable $month): self
    {
        $period = $house->billingPeriods()->with('bills')->where('month', $month->toDateString())->first();
        $monthEnd = $month->endOfMonth()->startOfDay();
        $startKey = (new Reading(ReadingKind::Initial, $month, 0))->sortKey();

        $units = Unit::sortNaturally($house->units()->with(['subMeters.readings', 'occupancies.tenant'])->get());
        $rows = [];

        foreach ($units as $unit) {
            $meters = [];
            foreach (Utility::cases() as $utility) {
                $unitMeters = $unit->subMeters
                    ->filter(fn (SubMeter $m): bool => $m->utility === $utility
                        && $m->installed_on->lessThanOrEqualTo($monthEnd)
                        && ($m->removed_on === null || $m->removed_on->greaterThanOrEqualTo($month)))
                    ->sortBy(fn (SubMeter $m): string => $m->installed_on->toDateString());

                foreach ($unitMeters as $meter) {
                    $meters[] = self::meterRow($meter, $period, $month, $monthEnd, $startKey);
                }
            }

            $occupants = $unit->occupancies
                ->filter(fn (Occupancy $o): bool => $o->started_on->lessThanOrEqualTo($monthEnd)
                    && ($o->ended_on === null || $o->ended_on->greaterThanOrEqualTo($month)))
                ->sortBy(fn (Occupancy $o): string => $o->started_on->toDateString())
                ->map(fn (Occupancy $o): string => $o->tenant->name
                    . ($o->started_on->greaterThan($month) ? ' (entrée le ' . $o->started_on->format('d/m') . ')' : '')
                    . ($o->ended_on !== null && $o->ended_on->lessThanOrEqualTo($monthEnd) ? ' (sortie le ' . $o->ended_on->format('d/m') . ')' : ''))
                ->values()
                ->all();

            if ($meters !== []) {
                $rows[] = ['unit' => $unit, 'occupants' => $occupants, 'meters' => $meters];
            }
        }

        return new self($month, $period, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function meterRow(SubMeter $meter, ?BillingPeriod $period, CarbonImmutable $month, CarbonImmutable $monthEnd, string $startKey): array
    {
        $monthEndReading = $period === null ? null : $meter->readings->first(
            fn (MeterReading $r): bool => $r->billing_period_id === $period->id && $r->kind === ReadingKind::MonthEnd,
        );

        $chain = $meter->readingChain($monthEndReading?->id);
        $start = $chain[0];
        $events = [];
        foreach ($chain as $reading) {
            if (strcmp($reading->sortKey(), $startKey) <= 0) {
                $start = $reading;
            } elseif ($reading->readOn->lessThanOrEqualTo($monthEnd)) {
                $events[] = $reading;
            }
        }

        return [
            'meter' => $meter,
            'utility' => $meter->utility,
            'needsMonthEnd' => $meter->needsMonthEndReading($monthEnd),
            'start' => $start,
            'startLabel' => self::label($start, $month),
            'events' => array_map(fn (Reading $r): string => self::label($r, $month), $events),
            'monthEnd' => $monthEndReading,
            // Brute, tous occupants confondus : les relevés intermédiaires s'annulent (EF-16).
            // La répartition par payeur et le crédit viennent avec le calcul (tranche 5).
            'consumption' => $monthEndReading === null
                ? null
                : Milli::fromDecimal($monthEndReading->index_value) - $start->index,
        ];
    }

    private static function label(Reading $reading, CarbonImmutable $month): string
    {
        $what = match ($reading->kind) {
            ReadingKind::Installation, ReadingKind::Initial => 'pose',
            ReadingKind::Entry => 'entrée',
            ReadingKind::Exit => 'sortie',
            ReadingKind::Removal => 'dépose',
            ReadingKind::MonthEnd => $reading->readOn->lessThan($month) ? 'fin ' . $reading->readOn->locale('fr')->isoFormat('MMMM') : 'fin de mois',
        };

        return sprintf('%s le %s (%s)', Milli::format($reading->index), $reading->readOn->format('d/m'), $what);
    }

    /**
     * @return array{needed: int, done: int, estimated: int}
     */
    public function progress(): array
    {
        $needed = 0;
        $done = 0;
        $estimated = 0;
        foreach ($this->units as $row) {
            foreach ($row['meters'] as $meter) {
                if ($meter['needsMonthEnd']) {
                    $needed++;
                    $done += $meter['monthEnd'] !== null ? 1 : 0;
                    $estimated += $meter['monthEnd']?->is_estimated ? 1 : 0;
                }
            }
        }

        return ['needed' => $needed, 'done' => $done, 'estimated' => $estimated];
    }
}
