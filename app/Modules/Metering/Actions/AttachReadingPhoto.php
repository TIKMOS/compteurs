<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\MeterReading;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Support\Journal;
use App\Shared\Support\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Joint (ou remplace, ou retire) la photo du décompteur à un relevé, comme preuve (lot 3).
 * Fichier privé sur le disque « local », réduit à 1 280 px en JPEG. Mois clôturé : refusé.
 */
final class AttachReadingPhoto
{
    public const DISK = 'local';

    /**
     * @throws PeriodClosedException
     */
    public function execute(MeterReading $reading, ?UploadedFile $photo): MeterReading
    {
        $reading->loadMissing(['billingPeriod', 'subMeter.unit']);
        if ($reading->billingPeriod->isClosed()) {
            throw new PeriodClosedException($reading->billingPeriod);
        }

        $previous = $reading->photo_path;
        $path = null;

        if ($photo !== null) {
            $path = sprintf('photos/releves/%s/%d-%s.jpg', $reading->billingPeriod->ulid, $reading->id, Str::lower(Str::random(12)));
            Storage::disk(self::DISK)->put($path, ImageResizer::toJpeg((string) $photo->get()));
        }

        $reading->forceFill(['photo_path' => $path])->save();

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        Journal::record($photo === null ? 'photo_removed' : 'photo_attached', sprintf(
            'Photo %s : %s (%s), %s',
            $photo === null ? 'retirée' : 'jointe',
            $reading->subMeter->unit->label,
            $reading->subMeter->utility->label(),
            $reading->billingPeriod->label(),
        ), $reading);

        return $reading;
    }
}
