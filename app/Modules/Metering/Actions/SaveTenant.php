<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Metering\Support\Journal;
use App\Shared\Support\BeninPhone;

/**
 * Crée ou modifie un locataire (UC-04, EF-06). Le téléphone est normalisé au format +229 ;
 * la validation (format, unicité chez le propriétaire) est faite par l'écran.
 */
final class SaveTenant
{
    public function execute(User $owner, string $name, ?string $phone, ?Tenant $tenant = null): Tenant
    {
        $attributes = [
            'name' => trim($name),
            'phone' => filled($phone) ? BeninPhone::normalize($phone) : null,
        ];

        if ($tenant === null) {
            $tenant = new Tenant($attributes);
            $tenant->owner()->associate($owner);
            $tenant->save();
            Journal::record('created', "Locataire {$tenant->name} ajouté", $tenant);

            return $tenant;
        }

        $tenant->update($attributes);
        Journal::record('updated', "Locataire {$tenant->name} modifié", $tenant, ['phone' => $tenant->phone]);

        return $tenant;
    }
}
