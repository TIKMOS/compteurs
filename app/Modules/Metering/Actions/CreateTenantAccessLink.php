<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Tenant;
use App\Models\TenantAccessLink;
use App\Models\User;
use App\Modules\Metering\Support\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée le lien personnel d'un locataire (EF-38, UC-19) et désactive le précédent. Le jeton en
 * clair n'est renvoyé qu'ici, une seule fois : la base ne garde que son empreinte (ENF-09).
 */
final class CreateTenantAccessLink
{
    /**
     * @return string l'adresse complète à envoyer au locataire
     */
    public function execute(Tenant $tenant, User $by): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($tenant, $by, $token): void {
            $tenant->accessLinks()->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);
            $tenant->accessLinks()->create([
                'token_hash' => TenantAccessLink::hash($token),
                'created_by' => $by->id,
            ]);
        });

        Journal::record('access_link_created', "Lien personnel créé pour {$tenant->name}", $tenant);

        return route('tenant.portal', $token);
    }
}
