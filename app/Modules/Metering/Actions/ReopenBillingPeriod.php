<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\ConsumptionCredit;
use App\Models\CreditMovement;
use App\Models\MeterReading;
use App\Modules\Metering\Exceptions\ReopenRefusedException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Modules\Metering\Support\Journal;
use App\Shared\Enums\PeriodStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réouverture du dernier mois clôturé d'une maison, pour correction (UC-16, EF-34, H-29).
 *
 * Conditions : c'est le dernier mois clôturé ; le mois suivant n'a aucun relevé ; aucun
 * remboursement de crédit du mois n'est marqué effectué. Les résultats figés du mois
 * (participations, prix figés, lots et mouvements de crédit, soldes de sortie) sont effacés ;
 * les relevés et les factures saisis restent. La prochaine clôture recalcule tout.
 */
final class ReopenBillingPeriod
{
    public const MIN_REASON_LENGTH = 10;

    /**
     * @throws ReopenRefusedException
     */
    public function execute(BillingPeriod $period, string $reason): BillingPeriod
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($period, $reason): BillingPeriod {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->with('house')->firstOrFail();
            $this->assertReopenable($period, $reason);

            $billIds = $period->bills()->pluck('id');

            // Résultats figés du mois.
            DB::table('charges')->whereIn('utility_bill_id', $billIds)->delete();
            $period->bills()->update([
                'utility_pricing_id' => null,
                'pricing_mode' => null,
                'fixed_unit_price' => null,
                'cap_to_actual' => null,
                'total_consumption' => null,
                'actual_unit_price' => null,
                'unit_price' => null,
                'price_source' => null,
            ]);
            // Une facture créée seulement pour porter les prix figés (mode 2 sans facture) disparaît.
            $period->bills()->whereNull('total_amount')->whereNull('main_consumption')->delete();

            // Crédit : mouvements du mois (imputations, soldes) puis lots créés ce mois.
            CreditMovement::query()->where('billing_period_id', $period->id)->delete();
            ConsumptionCredit::query()->where('created_in_billing_period_id', $period->id)->delete();
            $period->creditSettlements()->delete();

            $period->forceFill([
                'status' => PeriodStatus::Draft,
                'closed_at' => null,
                'closed_by' => null,
                'reopened_at' => CarbonImmutable::now(),
                'reopen_reason' => $reason,
            ])->save();

            Journal::record('reopened', 'Mois ' . ComputeBillingPeriod::of($period->label()) . ' rouvert : ' . $reason, $period, [
                'reason' => $reason,
            ]);

            return $period;
        });
    }

    /**
     * Raison du refus, ou null si la réouverture est possible (pour l'écran).
     */
    public function refusal(BillingPeriod $period): ?string
    {
        try {
            $this->assertReopenable($period, str_repeat('x', self::MIN_REASON_LENGTH));
        } catch (ReopenRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @throws ReopenRefusedException
     */
    private function assertReopenable(BillingPeriod $period, string $reason): void
    {
        if (! $period->isClosed()) {
            throw new ReopenRefusedException(Str::ucfirst($period->label()) . ' n\'est pas clôturé.');
        }

        $lastClosed = $period->house->lastClosedMonth();
        if ($lastClosed === null || ! $lastClosed->equalTo($period->month)) {
            throw new ReopenRefusedException(sprintf(
                'Seul le dernier mois clôturé (%s) peut être rouvert.',
                $lastClosed?->settings(['locale' => 'fr'])->isoFormat('MMMM YYYY') ?? '—',
            ));
        }

        $next = BillingPeriod::query()
            ->where('house_id', $period->house_id)
            ->where('month', $period->month->addMonthNoOverflow()->toDateString())
            ->first();
        if ($next !== null && MeterReading::query()->where('billing_period_id', $next->id)->exists()) {
            throw new ReopenRefusedException(sprintf(
                'Des relevés sont déjà enregistrés pour %s (fin de mois, entrée ou sortie) : effacez-les avant de rouvrir %s.',
                $next->label(),
                $period->label(),
            ));
        }

        if ($period->creditSettlements()->whereNotNull('refunded_at')->exists()) {
            throw new ReopenRefusedException('Un remboursement de crédit de ce mois est déjà marqué comme effectué : le mois ne peut plus être rouvert.');
        }

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new ReopenRefusedException('Indiquez le motif de la réouverture (au moins ' . self::MIN_REASON_LENGTH . ' caractères).');
        }
    }
}
