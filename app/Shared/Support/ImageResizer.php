<?php

declare(strict_types=1);

namespace App\Shared\Support;

use InvalidArgumentException;

/**
 * Réduit une photo (JPEG, PNG, WebP) à un côté maximal et la ré-encode en JPEG, avec GD :
 * une photo de téléphone de 4 Mo devient ~200 Ko (ENF-02, connexion lente).
 */
final class ImageResizer
{
    public static function toJpeg(string $binary, int $maxSide = 1280, int $quality = 75): string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new InvalidArgumentException('Image illisible.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        // Fond blanc pour les PNG transparents.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, $quality);

        return (string) ob_get_clean();
    }
}
