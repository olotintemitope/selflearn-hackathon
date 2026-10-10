<?php

namespace App\Services\Ocr;

final class MaterialExtractionRouter implements OcrClient
{
    private const MIN_READABLE_CHARACTERS = 40;

    public function __construct(private ProcessRunner $processes, private CloudOcrClient $cloud) {}

    public function extract(string $bytes, string $mimeType): OcrResult
    {
        return $mimeType === 'application/pdf'
            ? $this->extractPdf($bytes)
            : $this->extractImage($bytes, $mimeType);
    }

    private function extractImage(string $bytes, string $mimeType): OcrResult
    {
        $local = $this->processes->run([
            config('services.extraction.tesseract_binary', 'tesseract'), 'stdin', 'stdout',
            '-l', config('services.extraction.tesseract_language', 'eng'), '--psm', '6',
        ], $bytes, config('services.extraction.process_timeout', 30));
        $text = trim($local->output);

        if ($local->successful && $this->isReadable($text) && ! $this->looksMathematical($text)) {
            return new OcrResult(
                [['page' => 1, 'content' => $text, 'extractor' => 'tesseract', 'warnings' => []]],
                null, null, 'tesseract',
            );
        }

        $reason = $local->successful
            ? 'Local OCR quality was insufficient; Mathpix was used.'
            : 'Local OCR failed; Mathpix was used.';
        $cloud = $this->cloud->extract($bytes, $mimeType);

        return new OcrResult(
            $this->decoratePages($cloud->pages, 'mathpix', [$reason]),
            $cloud->confidence, $cloud->providerRequestId, 'mathpix', [$reason],
        );
    }

    private function extractPdf(string $bytes): OcrResult
    {
        $path = tempnam(sys_get_temp_dir(), 'selflearn-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new OcrException('Could not create a controlled temporary PDF for extraction.');
        }

        try {
            $info = $this->processes->run([
                config('services.extraction.pdfinfo_binary', 'pdfinfo'), $path,
            ], null, config('services.extraction.process_timeout', 30));
            if ($info->successful && preg_match('/^Pages:\s+(\d+)$/mi', $info->output, $matches) === 1
                && (int) $matches[1] > config('services.extraction.max_pdf_pages', 50)) {
                throw new OcrException('This PDF exceeds the configured page limit.');
            }

            $result = $this->processes->run([
                config('services.extraction.pdftotext_binary', 'pdftotext'),
                '-layout', '-enc', 'UTF-8', $path, '-',
            ], null, config('services.extraction.process_timeout', 30));

            if (! $result->successful) {
                return $this->cloudFallback($bytes, 'application/pdf', 'Local PDF text extraction failed; Mathpix was used.');
            }

            $rawPages = preg_split('/\f/u', $result->output) ?: [];
            if (end($rawPages) === '') {
                array_pop($rawPages);
            }
            if (count($rawPages) > config('services.extraction.max_pdf_pages', 50)) {
                throw new OcrException('This PDF exceeds the configured page limit.');
            }
            $pages = [];
            $warnings = [];
            foreach ($rawPages as $index => $rawPage) {
                $pageNumber = $index + 1;
                $text = trim($rawPage);
                if ($this->isReadable($text) && ! $this->looksMathematical($text)) {
                    $pages[] = ['page' => $pageNumber, 'content' => $text, 'extractor' => 'pdftotext', 'warnings' => []];

                    continue;
                }

                $page = $this->extractPdfPage($path, $pageNumber, $text);
                $pages[] = $page;
                $warnings = array_merge($warnings, $page['warnings']);
            }

            if ($pages === []) {
                return $this->cloudFallback($bytes, 'application/pdf', 'The PDF had no usable local text; Mathpix was used.');
            }

            $extractors = array_values(array_unique(array_column($pages, 'extractor')));

            return new OcrResult(
                $pages, null, null, count($extractors) > 1 ? 'mixed' : $extractors[0],
                array_values(array_unique($warnings)),
            );
        } finally {
            @unlink($path);
        }
    }

    /** @return array{page:int, content:string, extractor:string, warnings:list<string>} */
    private function extractPdfPage(string $pdfPath, int $pageNumber, string $textLayer): array
    {
        $image = $this->processes->run([
            config('services.extraction.magick_binary', 'magick'), '-density', '300',
            $pdfPath.'['.($pageNumber - 1).']', '-background', 'white', '-alpha', 'remove', 'png:-',
        ], null, config('services.extraction.process_timeout', 30));

        if (! $image->successful || $image->output === '') {
            if ($this->isReadable($textLayer)) {
                return [
                    'page' => $pageNumber, 'content' => $textLayer, 'extractor' => 'pdftotext',
                    'warnings' => ['Page '.$pageNumber.' may contain degraded mathematical text.'],
                ];
            }
            throw new OcrException('Could not rasterize PDF page '.$pageNumber.' for OCR.');
        }

        $local = $this->processes->run([
            config('services.extraction.tesseract_binary', 'tesseract'), 'stdin', 'stdout',
            '-l', config('services.extraction.tesseract_language', 'eng'), '--psm', '6',
        ], $image->output, config('services.extraction.process_timeout', 30));
        $localText = trim($local->output);

        if ($local->successful && $this->isReadable($localText) && ! $this->looksMathematical($localText)) {
            return [
                'page' => $pageNumber, 'content' => $localText, 'extractor' => 'tesseract',
                'warnings' => ['Page '.$pageNumber.' required local OCR.'],
            ];
        }

        $cloud = $this->cloud->extract($image->output, 'image/png');
        $content = trim((string) ($cloud->pages[0]['content'] ?? ''));
        if ($content === '') {
            throw new OcrException('Mathpix returned no readable text for PDF page '.$pageNumber.'.');
        }

        return [
            'page' => $pageNumber, 'content' => $content, 'extractor' => 'mathpix',
            'warnings' => ['Page '.$pageNumber.' required Mathpix after local extraction was insufficient.'],
            'provider_request_id' => $cloud->providerRequestId,
            'confidence' => $cloud->confidence,
        ];
    }

    private function cloudFallback(string $bytes, string $mimeType, string $warning): OcrResult
    {
        $cloud = $this->cloud->extract($bytes, $mimeType);

        return new OcrResult(
            $this->decoratePages($cloud->pages, 'mathpix', [$warning]),
            $cloud->confidence, $cloud->providerRequestId, 'mathpix', [$warning],
        );
    }

    private function isReadable(string $text): bool
    {
        $plain = preg_replace('/\s+/u', '', $text) ?? '';
        $readable = preg_replace('/[^\pL\pN]/u', '', $plain) ?? '';

        return mb_strlen($plain) >= self::MIN_READABLE_CHARACTERS
            && mb_strlen($readable) / max(1, mb_strlen($plain)) >= 0.55
            && ! str_contains($text, "\u{FFFD}");
    }

    private function looksMathematical(string $text): bool
    {
        return preg_match('/(?:[=∫∑√±×÷^]|\\\\(?:frac|sum|int|sqrt)|\b[a-z]\s*[+\-*\/]\s*\d)/iu', $text) === 1;
    }

    /** @return array<int, array{page:int|null, content:string, extractor:string, warnings:list<string>, provider_request_id?:string|null, confidence?:float|null}> */
    private function decoratePages(array $pages, string $extractor, array $warnings): array
    {
        return array_map(fn (array $page) => [
            'page' => $page['page'], 'content' => $page['content'],
            'extractor' => $page['extractor'] ?? $extractor,
            'warnings' => array_values(array_unique(array_merge($page['warnings'] ?? [], $warnings))),
            'provider_request_id' => $page['provider_request_id'] ?? null,
            'confidence' => $page['confidence'] ?? null,
        ], $pages);
    }
}
