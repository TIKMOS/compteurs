<?php

declare(strict_types=1);

namespace App\Modules\Metering\Http\Controllers;

use App\Models\House;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\TenantAccessLink;
use App\Modules\Metering\Actions\AttachReadingPhoto;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Affiche la photo d'un relevé : au propriétaire de la maison, ou au locataire (par son lien)
 * qui occupait le logement pendant le mois du relevé. Fichier privé, jamais servi directement.
 */
final class ReadingPhotoController
{
    public function owner(House $house, MeterReading $reading): StreamedResponse
    {
        Gate::authorize('view', $house);
        abort_unless($reading->subMeter->unit()->withTrashed()->value('house_id') === $house->id, 404);

        return $this->stream($reading);
    }

    public function tenant(string $token, MeterReading $reading): StreamedResponse
    {
        $link = TenantAccessLink::query()->where('token_hash', TenantAccessLink::hash($token))->whereNull('revoked_at')->first();
        abort_if($link === null, 404);

        $period = $reading->billingPeriod;
        $occupied = Occupancy::query()
            ->where('tenant_id', $link->tenant_id)
            ->where('unit_id', $reading->subMeter->unit_id)
            ->where('started_on', '<=', $period->monthEnd()->toDateString())
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $period->month->toDateString()))
            ->exists();
        abort_unless($occupied && $period->isClosed(), 404);

        return $this->stream($reading);
    }

    private function stream(MeterReading $reading): StreamedResponse
    {
        abort_if($reading->photo_path === null || ! Storage::disk(AttachReadingPhoto::DISK)->exists($reading->photo_path), 404);

        return Storage::disk(AttachReadingPhoto::DISK)->response($reading->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
