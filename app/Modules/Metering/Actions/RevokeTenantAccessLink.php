<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Tenant;
use App\Modules\Metering\Support\Journal;
use Carbon\CarbonImmutable;

/**
 * Révoque le lien personnel d'un locataire (EF-38) : l'adresse cesse aussitôt de fonctionner.
 */
final class RevokeTenantAccessLink
{
    public function execute(Tenant $tenant): void
    {
        $count = $tenant->accessLinks()->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);

        if ($count > 0) {
            Journal::record('access_link_revoked', "Lien personnel de {$tenant->name} révoqué", $tenant);
        }
    }
}
