<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

final class MathpixOcrClient implements PdfPageFigureOcrClient
{
    public function __construct(private HttpFactory $http) {}

    public function extract(string $bytes, string $mimeType): OcrResult
    {
        $this->ensureConfigured();

        try {
            return $mimeType === 'application/pdf'
                ? $this->extractPdf($bytes)
                : $this->extractImage($bytes, $mimeType);
        } catch (ConnectionException) {
            throw new OcrException('Mathpix could not be reached. Please retry.');
        }
    }

    public function extractPdfPages(string $bytes, array $pageNumbers): OcrResult
    {
        $pageNumbers = array_values(array_unique(array_filter($pageNumbers, fn ($page) => is_int($page) && $page > 0)));
        sort($pageNumbers);
        if ($pageNumbers === []) {
            throw new OcrException('At least one PDF page is required for Mathpix processing.');
        }
        $this->ensureConfigured();

        try {
            return $this->extractPdf($bytes, $pageNumbers);
        } catch (ConnectionException) {
            throw new OcrException('Mathpix could not be reached. Please retry.');
        }
    }

    private function extractImage(string $bytes, string $mimeType): OcrResult
    {
        $response = $this->client()->post(config('services.mathpix.image_endpoint'), [
            'src' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($bytes)),
            'formats' => ['text'],
            'enable_document_layout' => true,
            'include_line_data' => true,
            'metadata' => ['improve_mathpix' => false],
        ]);

        $this->assertSuccessful($response);
        $text = trim((string) $response->json('text'));
        if ($text === '') {
            throw new OcrException('Mathpix returned no readable text.');
        }
        $confidence = is_numeric($response->json('confidence')) ? (float) $response->json('confidence') : null;
        $confidenceRate = is_numeric($response->json('confidence_rate')) ? (float) $response->json('confidence_rate') : null;

        return new OcrResult(
            [['page' => 1, 'content' => $text, 'extractor' => 'mathpix', 'warnings' => [], 'confidence' => $confidence, 'confidence_rate' => $confidenceRate]],
            $confidence,
            $response->json('request_id'),
            'mathpix',
            [],
            $confidenceRate,
        );
    }

    /** @param list<int>|null $pageNumbers */
    private function extractPdf(string $bytes, ?array $pageNumbers = null): OcrResult
    {
        $options = [
            'math_inline_delimiters' => ['$', '$'],
            'include_chemistry_as_image' => true,
            'metadata' => ['improve_mathpix' => false],
        ];
        if ($pageNumbers !== null) {
            $options['page_ranges'] = implode(',', $pageNumbers);
        }
        $response = $this->client()
            ->attach('file', $bytes, 'material.pdf')
            ->attach('options_json', json_encode($options, JSON_THROW_ON_ERROR))
            ->post(config('services.mathpix.pdf_endpoint'));
        $this->assertSuccessful($response);

        $pdfId = $response->json('pdf_id');
        if (! is_string($pdfId) || preg_match('/\A[a-zA-Z0-9_-]{1,200}\z/', $pdfId) !== 1) {
            throw new OcrException('Mathpix did not return a document identifier.');
        }

        $maxPollAttempts = max(1, min(120, (int) config('services.mathpix.pdf_max_poll_attempts', 45)));
        $pollIntervalMilliseconds = max(0, min(5000, (int) config('services.mathpix.pdf_poll_interval_ms', 1000)));

        try {
            for ($attempt = 0; $attempt < $maxPollAttempts; $attempt++) {
                $status = $this->client()->get(config('services.mathpix.pdf_endpoint').'/'.$pdfId);
                $this->assertSuccessful($status);
                if ($status->json('status') === 'completed') {
                    $lines = $this->client()->get(config('services.mathpix.pdf_endpoint').'/'.$pdfId.'.lines.json');
                    $this->assertSuccessful($lines);

                    return new OcrResult($this->parsePdfPages($lines->body(), $pdfId), null, $pdfId, 'mathpix');
                }
                if ($status->json('status') === 'error') {
                    throw new OcrException('Mathpix could not process this PDF.');
                }
                if ($attempt + 1 < $maxPollAttempts && $pollIntervalMilliseconds > 0) {
                    usleep($pollIntervalMilliseconds * 1000);
                }
            }

            throw new OcrException('Mathpix PDF processing timed out. Please retry.');
        } finally {
            try {
                // Cropped images are copied into private app storage by the caller.
                $this->client()->delete(config('services.mathpix.pdf_endpoint').'/'.$pdfId);
            } catch (\Throwable) {
                // Provider cleanup must not mask the extraction result or its failure.
            }
        }
    }

    private function client(): PendingRequest
    {
        return $this->http->withHeaders([
            'app_id' => config('services.mathpix.app_id'),
            'app_key' => config('services.mathpix.app_key'),
        ])->acceptJson()->timeout(30)->retry(2, 250, throw: false);
    }

    private function ensureConfigured(): void
    {
        if (! config('services.mathpix.app_id') || ! config('services.mathpix.app_key')) {
            throw new OcrException('Mathpix credentials are not configured.');
        }
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->failed()) {
            throw new OcrException('Mathpix is temporarily unavailable (HTTP '.$response->status().').');
        }
    }

    /** @return array<int, array{page:int, content:string, extractor:string, warnings:list<string>, provider_request_id:string, figure_assets:list<array{bytes:string,mime_type:string,alt:string,source_url:string}>}> */
    private function parsePdfPages(string $body, string $pdfId): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['pages']) && is_array($decoded['pages'])) {
            $pages = $decoded['pages'];
        } elseif (is_array($decoded) && array_is_list($decoded)) {
            $pages = $decoded;
        } else {
            $pages = collect(preg_split('/\R/', trim($body)) ?: [])
                ->map(fn (string $line) => json_decode($line, true))
                ->filter(fn ($page) => is_array($page))
                ->values()->all();
        }

        $normalized = collect($pages)->map(function (array $page, int $index) use ($pdfId): array {
            $pageNumber = (int) ($page['page'] ?? $index + 1);
            $figureAssets = $this->downloadFigureAssets($page['lines'] ?? [], $pageNumber);
            $content = collect($page['lines'] ?? [])
                ->map(fn ($line) => trim((string) ($line['text'] ?? '')))
                ->filter()->unique()->implode("\n");
            if ($content === '' && $figureAssets !== []) {
                $content = 'A diagram or illustration appears on this source page.';
            }

            return [
                'page' => $pageNumber,
                'content' => $content,
                'extractor' => 'mathpix',
                'warnings' => [],
                'provider_request_id' => $pdfId,
                'figure_assets' => $figureAssets,
            ];
        })->filter(fn (array $page) => $page['content'] !== '' || $page['figure_assets'] !== [])->values()->all();

        if ($normalized === []) {
            throw new OcrException('Mathpix returned no readable page text.');
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $lines
     * @return list<array{bytes:string,mime_type:string,alt:string,source_url:string}>
     */
    private function downloadFigureAssets(array $lines, int $pageNumber): array
    {
        $figures = [];
        $seen = [];
        foreach ($lines as $line) {
            $display = (string) ($line['text_display'] ?? '');
            if ($display === '') {
                continue;
            }
            preg_match_all('~https://cdn\.mathpix\.com/cropped/[^\s)\]}>]+~i', $display, $matches);
            foreach ($matches[0] ?? [] as $matchedUrl) {
                $url = rtrim($matchedUrl, "'\".,;");
                if (isset($seen[$url]) || ! $this->isMathpixCropUrl($url)) {
                    continue;
                }
                $seen[$url] = true;
                if (count($figures) >= 12) {
                    break 2;
                }

                try {
                    $response = $this->http->withOptions(['allow_redirects' => false])->timeout(15)->get($url);
                    if (! $response->successful()) {
                        continue;
                    }
                    $mimeType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
                    $imageBytes = $response->body();
                    if (strlen($imageBytes) > 5_000_000 || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
                        || @getimagesizefromstring($imageBytes) === false) {
                        continue;
                    }

                    $lineText = trim(strip_tags((string) ($line['text'] ?? '')));
                    $alt = $this->figureAlt($display, $url, $lineText);
                    $figures[] = [
                        'bytes' => $imageBytes,
                        'mime_type' => $mimeType,
                        'alt' => $alt !== '' ? $alt : 'Diagram or illustration from page '.$pageNumber,
                        'source_url' => $url,
                    ];
                } catch (ConnectionException) {
                    // A missing crop should not discard the page's recognized text.
                }
            }
        }

        return $figures;
    }

    private function isMathpixCropUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'cdn.mathpix.com'
            && str_starts_with((string) ($parts['path'] ?? ''), '/cropped/');
    }

    private function figureAlt(string $display, string $url, string $context = ''): string
    {
        if (preg_match('~!\[([^\]]*)\]\('.preg_quote($url, '~').'~', $display, $matches) === 1) {
            $alt = trim(strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)));
            if ($alt !== '') {
                return $alt;
            }
        }
        if (preg_match('~\\\\includegraphics\[[^\]]*alt=\{([^}]*)\}[^\]]*\]\{'.preg_quote($url, '~').'\}~', $display, $matches) === 1) {
            $alt = trim(strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)));
            if ($alt !== '') {
                return $alt;
            }
        }
        if ($context !== '' && preg_match('/\b(diagram|figure|chart|graph|illustration|flowchart|hierarchy)\b/i', $context) === 1) {
            return Str::limit($context, 180, '');
        }

        return '';
    }
}
