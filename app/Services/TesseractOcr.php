<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class TesseractOcr
{
    /** @return array{engine_version: ?string, confidence: float, raw_text: string, extracted_data: array{numbers: array<int, int>}} */
    public function extract(string $absolutePath): array
    {
        $process = new Process([
            config('ocr.binary'), $absolutePath, 'stdout', '-l', config('ocr.language'),
            '--psm', (string) config('ocr.page_segmentation_mode'), 'tsv',
        ]);
        $process->setTimeout(config('ocr.timeout'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Tesseract tidak dapat memproses foto.');
        }

        $words = [];
        $confidences = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue;
            }
            $columns = explode("\t", $line, 12);
            if (count($columns) < 12 || trim($columns[11]) === '') {
                continue;
            }
            $words[] = trim($columns[11]);
            if (is_numeric($columns[10]) && (float) $columns[10] >= 0) {
                $confidences[] = (float) $columns[10];
            }
        }
        $rawText = implode(' ', $words);
        preg_match_all('/(?<!\d)\d{1,7}(?!\d)/', str_replace(['.', ','], '', $rawText), $matches);

        return [
            'engine_version' => $this->version(),
            'confidence' => $confidences === [] ? 0 : round(array_sum($confidences) / count($confidences), 2),
            'raw_text' => $rawText,
            'extracted_data' => ['numbers' => array_map('intval', $matches[0] ?? [])],
        ];
    }

    private function version(): ?string
    {
        $process = new Process([config('ocr.binary'), '--version']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() ? str((string) strtok($process->getOutput(), "\r\n"))->limit(64)->toString() : null;
    }
}
