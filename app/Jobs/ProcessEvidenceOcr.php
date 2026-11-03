<?php

namespace App\Jobs;

use App\Models\OcrJob;
use App\Services\TesseractOcr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessEvidenceOcr implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $ocrJobId) {}

    public function handle(TesseractOcr $ocr): void
    {
        $job = OcrJob::query()->with('evidence')->findOrFail($this->ocrJobId);
        $job->update(['status' => 'processing', 'started_at' => now(), 'finished_at' => null, 'error_message' => null]);
        $temporaryPath = null;
        $source = null;
        $target = null;
        try {
            $source = Storage::disk($job->evidence->disk)->readStream($job->evidence->storage_key);
            if (! is_resource($source)) {
                throw new RuntimeException('Bukti laporan tidak dapat dibaca dari penyimpanan privat.');
            }
            $temporaryPath = tempnam(sys_get_temp_dir(), 'sena-ocr-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Temporary file OCR tidak dapat dibuat.');
            }
            $target = fopen($temporaryPath, 'wb');
            if (! is_resource($target) || stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('Bukti laporan tidak dapat disalin untuk OCR.');
            }
            fclose($target);
            $target = null;
            fclose($source);
            $source = null;

            $result = $ocr->extract($temporaryPath);
            $job->update([...$result, 'status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $job->update(['status' => 'failed', 'error_message' => str($exception->getMessage())->limit(1000)->toString(), 'finished_at' => now()]);
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
