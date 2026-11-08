<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\ConsumptionCredit;
use App\Models\House;
use App\Models\MeterReading;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Alertes du tableau de bord d'une maison : relevés manquants, estimations en cours, crédits de
 * consommation restants, mode 2 sans plafond. Chaque alerte porte un lien vers l'écran où agir.
 */
final class HouseAlerts
{
    public function __construct(private readonly MissingMonthEndReadings $missing) {}

    /**
     * @return list<array{type: string, tone: string, message: string, url: string}>
     */
    public function execute(House $house): array
    {
        $alerts = [];
        $current = CarbonImmutable::now()->startOfMonth();

        // Relevés manquants : mois en cours et mois précédents encore en brouillon.
        $months = BillingPeriod::query()
            ->where('house_id', $house->id)
            ->where('status', PeriodStatus::Draft)
            ->where('month', '<', $current->toDateString())
            ->orderBy('month')
            ->pluck('month')
            ->map(fn ($m) => CarbonImmutable::parse($m)->startOfMonth())
            ->push($current);

        foreach ($months as $month) {
            $row = collect($this->missing->forHouse($house, $month))->first();
            if ($row !== null) {
                $late = $month->lessThan($current);
                $alerts[] = [
                    'type' => 'missing_readings',
                    // Mois passé : en retard. Mois en cours : rappel neutre jusqu'à la fin du mois.
                    'tone' => $late ? 'danger' : 'neutral',
                    'message' => sprintf(
                        '%s : %d relevé%s de fin de mois %s.',
                        Str::ucfirst($month->locale('fr')->isoFormat('MMMM YYYY')),
                        $row['missing'],
                        $row['missing'] > 1 ? 's' : '',
                        $late ? 'manquant' . ($row['missing'] > 1 ? 's' : '') . ' : le mois ne peut pas être clôturé' : 'à saisir',
                    ),
                    'url' => route('periodes.show', [$house, $month->format('Y-m')]),
                ];
            }
        }

        // Estimations en cours : relevé estimé qui est encore le dernier relevé du décompteur.
        $estimated = MeterReading::query()
            ->where('kind', ReadingKind::MonthEnd)
            ->where('is_estimated', true)
            ->whereHas('subMeter', fn ($q) => $q->whereNull('removed_on')->whereHas('unit', fn ($u) => $u->where('house_id', $house->id)))
            ->with(['subMeter.unit', 'billingPeriod'])
            ->get()
            ->filter(fn (MeterReading $r): bool => ! MeterReading::query()
                ->where('sub_meter_id', $r->sub_meter_id)
                ->where('read_on', '>', $r->read_on->toDateString())
                ->exists());

        foreach ($estimated as $reading) {
            $alerts[] = [
                'type' => 'estimate_pending',
                'tone' => 'info',
                'message' => sprintf(
                    '%s (%s) : relevé de %s estimé, à régulariser au prochain relevé réel.',
                    $reading->subMeter->unit->label,
                    $reading->subMeter->utility->label(),
                    $reading->billingPeriod->label(),
                ),
                'url' => route('periodes.show', [$house, $reading->billingPeriod->month->format('Y-m')]),
            ];
        }

        // Crédits de consommation restants, par payeur, logement et fluide.
        $credits = ConsumptionCredit::query()
            ->whereHas('createdInPeriod', fn ($q) => $q->where('house_id', $house->id)->where('status', PeriodStatus::Closed))
            ->with(['movements', 'occupancy.tenant'])
            ->get()
            ->groupBy(fn (ConsumptionCredit $c): string => $c->occupancy_id . '|' . $c->unit_id . '|' . $c->utility->value);

        foreach ($credits as $lots) {
            $remaining = $lots->sum(fn (ConsumptionCredit $c): int => $c->remaining());
            if ($remaining <= 0) {
                continue;
            }
            $first = $lots->first();
            $alerts[] = [
                'type' => 'credit_remaining',
                'tone' => 'info',
                'message' => sprintf(
                    'Crédit de consommation restant : %s, %s %s (%s).',
                    $first->occupancy?->tenant->name ?? 'propriétaire (logement vacant)',
                    Milli::format($remaining),
                    $first->utility->unit(),
                    $first->utility->label(),
                ),
                'url' => route('maisons.show', $house),
            ];
        }

        // Mode 2 sans plafond pour le mois en cours.
        foreach (Utility::cases() as $utility) {
            $rule = $house->pricingFor($utility, $current)?->rule();
            if ($rule !== null && $rule->mode === PricingMode::FixedPrice && ! $rule->capToActual) {
                $alerts[] = [
                    'type' => 'uncapped_fixed_price',
                    'tone' => 'warning',
                    'message' => sprintf(
                        '%s : prix fixé sans plafond au prix réel (%s). Vous restez seul responsable du respect de vos contrats SBEE et SONEB.',
                        Str::ucfirst($utility->label()),
                        $rule->summary($utility->unit()),
                    ),
                    'url' => route('maisons.settings', $house),
                ];
            }
        }

        return $alerts;
    }
}
