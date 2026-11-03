<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class EvidenceImageQuality
{
    public const MIN_SHORT_EDGE = 720;

    public const MIN_LONG_EDGE = 1280;

    public const MAX_PIXELS = 16_000_000;

    public function assertAcceptable(UploadedFile $file): void
    {
        $dimensions = @getimagesize($file->getRealPath());

        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
            throw ValidationException::withMessages(['evidence' => 'Foto bukti tidak dapat dibaca. Gunakan foto JPG, PNG, atau WebP yang valid.']);
        }

        [$width, $height] = [(int) $dimensions[0], (int) $dimensions[1]];
        if (min($width, $height) < self::MIN_SHORT_EDGE || max($width, $height) < self::MIN_LONG_EDGE) {
            throw ValidationException::withMessages(['evidence' => sprintf('Foto bukti terlalu kecil untuk diperiksa. Gunakan minimal %d × %d piksel.', self::MIN_LONG_EDGE, self::MIN_SHORT_EDGE)]);
        }

        if (($width * $height) > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['evidence' => 'Resolusi foto terlalu besar untuk diproses dengan aman. Perkecil ukuran foto lalu coba lagi.']);
        }
    }
}
