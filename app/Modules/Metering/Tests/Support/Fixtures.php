<?php

declare(strict_types=1);

namespace App\Modules\Metering\Tests\Support;

use App\Modules\Metering\Data\AllocationLine;
use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\EstimateOrigin;
use App\Modules\Metering\Data\OccupancySpan;
use App\Modules\Metering\Data\Reading;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;
use DateTimeImmutable;

/**
 * Raccourcis pour écrire les cas de test de CONCEPTION.md § 5 dans leurs unités (kWh, m³, dates).
 */
final class Fixtures
{
    /** Prix unitaires de l'exemple : électricité d'octobre, de novembre et de décembre 2026, eau de novembre. */
    public const string PU_OCT = '1773/13';

    public const string PU_NOV = '11780/89';

    public const string PU_DEC = '3000/17';

    public const string PU_NOV_WATER = '34540/59';

    public static function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd);
    }

    public static function m(string|int $value): int
    {
        return Milli::fromDecimal($value);
    }

    public static function monthEnd(string $ymd, string|int $index, bool $estimated = false): Reading
    {
        return new Reading(ReadingKind::MonthEnd, self::date($ymd), self::m($index), $estimated);
    }

    /**
     * Relevé estimé d'un mois clôturé : on connaît le payeur de la tranche et le prix unitaire du mois.
     */
    public static function estimatedMonthEnd(string $ymd, string|int $index, ?int $paidBy, string $unitPrice): Reading
    {
        return new Reading(
            ReadingKind::MonthEnd,
            self::date($ymd),
            self::m($index),
            true,
            null,
            new EstimateOrigin($paidBy, Fraction::parse($unitPrice), substr($ymd, 0, 7)),
        );
    }

    public static function initial(string $ymd, string|int $index): Reading
    {
        return new Reading(ReadingKind::Initial, self::date($ymd), self::m($index));
    }

    public static function entry(string $ymd, string|int $index, int $occupancyId): Reading
    {
        return new Reading(ReadingKind::Entry, self::date($ymd), self::m($index), false, $occupancyId);
    }

    public static function exit(string $ymd, string|int $index, int $occupancyId): Reading
    {
        return new Reading(ReadingKind::Exit, self::date($ymd), self::m($index), false, $occupancyId);
    }

    public static function occupancy(int $id, string $startedOn, ?string $endedOn = null): OccupancySpan
    {
        return new OccupancySpan($id, self::date($startedOn), $endedOn === null ? null : self::date($endedOn));
    }

    public static function lot(?int $occupancyId, string|int $quantity, string $unitPrice, string $month = '2026-11', ?int $id = null): CreditLot
    {
        return new CreditLot($occupancyId, self::m($quantity), Fraction::parse($unitPrice), $month, $id);
    }

    /**
     * @param list<CreditLot> $lots
     *
     * @return array<string, list<CreditLot>>
     */
    public static function lotsByPayer(array $lots): array
    {
        $byPayer = [];
        foreach ($lots as $lot) {
            $byPayer[CreditLot::payerKey($lot->occupancyId)][] = $lot;
        }

        return $byPayer;
    }

    /**
     * @param array<string, string|int> $consumptions libellé du logement => consommation
     *
     * @return list<AllocationLine>
     */
    public static function lines(array $consumptions): array
    {
        $lines = [];
        foreach ($consumptions as $label => $consumption) {
            $lines[] = new AllocationLine((string) $label, (string) $label, self::m($consumption));
        }

        return $lines;
    }

    /**
     * Maison « Cotonou Fidjrossè » de CONCEPTION.md § 5 : un décompteur par logement.
     *
     * @param array<int, array{0: string, 1: list<OccupancySpan>, 2: list<Reading>, 3?: list<CreditLot>}> $units id => [libellé, occupations, relevés, lots de crédit]
     *
     * @return list<array{unitId: int, unitLabel: string, occupancies: list<OccupancySpan>, subMeters: list<array{id: int, readings: list<Reading>}>, creditLots: array<string, list<CreditLot>>}>
     */
    public static function house(array $units): array
    {
        $result = [];
        foreach ($units as $id => $unit) {
            $result[] = [
                'unitId' => $id,
                'unitLabel' => $unit[0],
                'occupancies' => $unit[1],
                'subMeters' => [['id' => $id, 'readings' => $unit[2]]],
                'creditLots' => self::lotsByPayer($unit[3] ?? []),
            ];
        }

        return $result;
    }
}
