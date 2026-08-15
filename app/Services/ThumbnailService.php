<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ThumbnailService
{
    /**
     * Genera una miniatura JPEG en base64 (data URI) a partir de una foto en storage privado.
     * Requiere la extensión GD de PHP. Retorna null si la foto no existe o no se puede procesar.
     */
    public function makeThumbnailDataUri(string $photoPath, int $maxDim = 64): ?string
    {
        if (! Storage::disk('local')->exists($photoPath)) {
            return null;
        }

        $contents = Storage::disk('local')->get($photoPath);
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($maxDim / $width, $maxDim / $height, 1);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumbnail = imagescale($source, $newWidth, $newHeight);
        imagedestroy($source);

        if ($thumbnail === false) {
            return null;
        }

        ob_start();
        imagejpeg($thumbnail, null, 70);
        $jpegData = ob_get_clean();
        imagedestroy($thumbnail);

        return 'data:image/jpeg;base64,' . base64_encode($jpegData);
    }
}
