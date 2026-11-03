<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EvidenceImagePreview
{
    private const MAX_EDGE = 1920;

    private const WEBP_QUALITY = 82;

    /** @return array{key: string, media_type: string, size_bytes: int} */
    public function store(UploadedFile $file, string $disk, int $submissionId): array
    {
        $contents = @file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['evidence' => 'Foto bukti tidak dapat diproses untuk pratinjau. Silakan gunakan foto lain.']);
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::MAX_EDGE / max($width, $height));
            $previewWidth = max(1, (int) round($width * $scale));
            $previewHeight = max(1, (int) round($height * $scale));
            $preview = imagecreatetruecolor($previewWidth, $previewHeight);
            imagealphablending($preview, false);
            imagesavealpha($preview, true);
            $transparent = imagecolorallocatealpha($preview, 0, 0, 0, 127);
            imagefilledrectangle($preview, 0, 0, $previewWidth, $previewHeight, $transparent);
            imagecopyresampled($preview, $source, 0, 0, 0, 0, $previewWidth, $previewHeight, $width, $height);
            ob_start();
            $encoded = imagewebp($preview, null, self::WEBP_QUALITY);
            $previewContents = ob_get_clean();
            imagedestroy($preview);

            if (! $encoded || $previewContents === false) {
                throw ValidationException::withMessages(['evidence' => 'Pratinjau foto bukti gagal dibuat. Silakan coba lagi.']);
            }

            $key = sprintf('evidence-previews/%d/%s.webp', $submissionId, Str::uuid());
            if (! Storage::disk($disk)->put($key, $previewContents)) {
                throw ValidationException::withMessages(['evidence' => 'Pratinjau foto bukti gagal disimpan. Silakan coba lagi.']);
            }

            return ['key' => $key, 'media_type' => 'image/webp', 'size_bytes' => strlen($previewContents)];
        } finally {
            imagedestroy($source);
        }
    }
}
