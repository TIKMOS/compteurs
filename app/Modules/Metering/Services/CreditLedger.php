<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\BilledSegment;
use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\CreditMovement;
use App\Modules\Metering\Data\CreditResult;
use App\Modules\Metering\Data\Segment;
use App\Modules\Metering\Data\SettledCredit;
use App\Shared\Enums\CreditMovementKind;
use App\Shared\Support\Fraction;
use InvalidArgumentException;
use LogicException;

/**
 * Crédit de consommation d'un logement pour un fluide (EF-24). Classe pure.
 *
 * - tranche brute négative (index réel < index estimé) → facturée 0 ; un lot de crédit est créé
 *   pour le payeur de la tranche estimée, au prix unitaire du mois surestimé ;
 * - tranche positive → le crédit de **son** payeur est déduit, lots les plus anciens d'abord,
 *   sans jamais rendre la consommation négative ; le crédit ne passe pas d'un locataire à l'autre ;
 * - locataire sortant → son crédit restant est soldé et converti en FCFA, lot par lot.
 */
final class CreditLedger
{
    /**
     * @param list<Segment> $segments tranches du logement sur la période, tous décompteurs confondus
     * @param array<string, list<CreditLot>> $openingLots lots ouverts par payeur (CreditLot::payerKey), du plus ancien au plus récent
     * @param list<int> $exitingOccupancyIds occupations qui sortent ce mois-ci
     */
    public function apply(array $segments, array $openingLots, array $exitingOccupancyIds = []): CreditResult
    {
        usort($segments, static fn (Segment $a, Segment $b): int => [$a->end->sortKey(), $a->start->sortKey()]
            <=> [$b->end->sortKey(), $b->start->sortKey()]);

        $lots = $openingLots;
        $created = [];
        $movements = [];
        $billed = [];

        foreach ($lots as $payerLots) {
            foreach ($payerLots as $lot) {
                if ($lot->quantity <= 0) {
                    throw new InvalidArgumentException('Un lot de crédit ouvert doit avoir une quantité positive.');
                }
            }
        }

        foreach ($segments as $segment) {
            $raw = $segment->rawConsumption();

            if ($raw < 0) {
                $origin = $segment->start->estimateOrigin
                    ?? throw new LogicException('Tranche négative sans relevé estimé d\'origine : impossible de créer le crédit.');

                $lot = new CreditLot($origin->occupancyId, -$raw, $origin->unitPrice, $origin->month, ref: 'new:' . (count($created) + 1));
                $lots[CreditLot::payerKey($origin->occupancyId)][] = $lot;
                $created[] = $lot;
                $billed[] = new BilledSegment($segment, creditApplied: 0, creditCreated: -$raw, billed: 0);

                continue;
            }

            $key = CreditLot::payerKey($segment->occupancyId);
            $remaining = $raw;
            $applied = 0;
            $payerLots = $lots[$key] ?? [];

            foreach ($payerLots as $i => $lot) {
                if ($remaining === 0) {
                    break;
                }
                $take = min($lot->quantity, $remaining);
                $movements[] = new CreditMovement(CreditMovementKind::Applied, $lot, $take);
                $payerLots[$i] = $lot->withQuantity($lot->quantity - $take);
                $remaining -= $take;
                $applied += $take;
            }

            if ($payerLots !== []) {
                $lots[$key] = array_values(array_filter($payerLots, static fn (CreditLot $l): bool => $l->quantity > 0));
            }

            $billed[] = new BilledSegment($segment, creditApplied: $applied, creditCreated: 0, billed: $raw - $applied);
        }

        $settlements = [];
        foreach (array_unique($exitingOccupancyIds) as $occupancyId) {
            $key = CreditLot::payerKey($occupancyId);
            $quantity = 0;
            $value = Fraction::zero();

            foreach ($lots[$key] ?? [] as $lot) {
                $lotValue = $lot->valueOf($lot->quantity);
                $movements[] = new CreditMovement(CreditMovementKind::Settled, $lot, $lot->quantity, $lotValue);
                $quantity += $lot->quantity;
                $value = $value->add($lotValue);
            }

            if ($quantity > 0) {
                $settlements[$occupancyId] = new SettledCredit($occupancyId, $quantity, $value);
            }
            unset($lots[$key]);
        }

        return new CreditResult(
            $billed,
            array_filter($lots, static fn (array $payerLots): bool => $payerLots !== []),
            $created,
            $movements,
            $settlements,
        );
    }
}
