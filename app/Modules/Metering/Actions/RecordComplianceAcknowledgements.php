<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\User;
use App\Models\UtilityPricing;
use App\Shared\Enums\AcknowledgementKind;
use Carbon\CarbonImmutable;

/**
 * Enregistre une confirmation par événement (EF-49, H-35), avec le texte exact affiché.
 * Appelée à l'intérieur de la transaction de l'Action qui crée ou modifie la tarification.
 */
final class RecordComplianceAcknowledgements
{
    /**
     * @param list<AcknowledgementKind> $kinds
     */
    public function record(UtilityPricing $version, array $kinds, User $user, ?string $ipAddress): void
    {
        $now = CarbonImmutable::now();

        foreach ($kinds as $kind) {
            $version->complianceAcknowledgements()->create([
                'user_id' => $user->id,
                'house_id' => $version->house_id,
                'utility' => $version->utility,
                'kind' => $kind,
                'notice_text' => (string) config('legal.compliance_notice'),
                'acknowledged_at' => $now,
                'ip_address' => $ipAddress,
            ]);
        }
    }
}
