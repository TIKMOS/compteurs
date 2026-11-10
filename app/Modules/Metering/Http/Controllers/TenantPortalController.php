<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\Occupancy;
use App\Models\TenantAccessLink;
use App\Modules\Metering\Data\TenantStatement;
use App\Modules\Metering\Queries\BuildTenantStatement;
use App\Shared\Enums\PeriodStatus;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Espace du locataire, ouvert par son lien personnel (EF-38, UC-19) : lecture seule de ses
 * propres fiches des mois clôturés, sans compte. Un lien révoqué ou inconnu répond 404.
 */
final class TenantPortalController
{
    public function show(string $token, BuildTenantStatement $build): View
    {
        $link = $this->link($token);

        return view('pages.espace-locataire', [
            'tenant' => $link->tenant,
            'token' => $token,
            'statements' => $this->statements($link, $build),
        ]);
    }

    public function pdf(string $token, Occupancy $occupancy, string $mois, BuildTenantStatement $build): PdfBuilder
    {
        $link = $this->link($token);
        abort_if($occupancy->tenant_id !== $link->tenant_id, 404);

        $period = $this->closedPeriodFor($occupancy, $mois);
        abort_if($period === null, 404);

        $statement = $build->execute($period, $occupancy);
        abort_if($statement->utilities === [], 404);

        return Pdf::view('pdf.fiche-locataire', ['s' => $statement])
            ->driver('dompdf')
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name($statement->fileName());
    }

    private function link(string $token): TenantAccessLink
    {
        $link = TenantAccessLink::query()
            ->where('token_hash', TenantAccessLink::hash($token))
            ->whereNull('revoked_at')
            ->with(['tenant' => fn ($q) => $q->withTrashed()])
            ->first();

        abort_if($link === null || $link->tenant === null, 404);

        $link->forceFill(['last_used_at' => CarbonImmutable::now()])->saveQuietly();

        return $link;
    }

    /**
     * Fiches des mois clôturés où le locataire a une participation, du plus récent au plus ancien.
     *
     * @return list<TenantStatement>
     */
    private function statements(TenantAccessLink $link, BuildTenantStatement $build): array
    {
        $occupancies = Occupancy::query()->where('tenant_id', $link->tenant_id)->with(['tenant' => fn ($q) => $q->withTrashed(), 'unit'])->get();
        $statements = [];

        foreach ($occupancies as $occupancy) {
            $periodIds = Charge::query()
                ->where('occupancy_id', $occupancy->id)
                ->join('utility_bills', 'utility_bills.id', '=', 'charges.utility_bill_id')
                ->distinct()
                ->pluck('utility_bills.billing_period_id');

            $periods = BillingPeriod::query()
                ->whereIn('id', $periodIds)
                ->where('status', PeriodStatus::Closed)
                ->whereHas('house', fn ($q) => $q->whereNull('deleted_at'))
                ->get();

            foreach ($periods as $period) {
                $statements[] = $build->execute($period, $occupancy);
            }
        }

        usort($statements, fn (TenantStatement $a, TenantStatement $b): int => $b->period->month <=> $a->period->month
            ?: strnatcasecmp($a->unit->label, $b->unit->label));

        return $statements;
    }

    private function closedPeriodFor(Occupancy $occupancy, string $mois): ?BillingPeriod
    {
        return BillingPeriod::query()
            ->where('house_id', $occupancy->unit->house_id)
            ->where('month', $mois . '-01')
            ->where('status', PeriodStatus::Closed)
            ->whereHas('house', fn ($q) => $q->whereNull('deleted_at'))
            ->first();
    }
}
