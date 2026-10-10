<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class MathpixOcrClient implements CloudOcrClient
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

    private function extractImage(string $bytes, string $mimeType): OcrResult
    {
        $response = $this->client()->post(config('services.mathpix.image_endpoint'), [
            'src' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($bytes)),
            'formats' => ['text'],
            'include_line_data' => true,
            'metadata' => ['improve_mathpix' => false],
        ]);

        $this->assertSuccessful($response);
        $text = trim((string) $response->json('text'));
        if ($text === '') {
            throw new OcrException('Mathpix returned no readable text.');
        }

        return new OcrResult(
            [['page' => 1, 'content' => $text, 'extractor' => 'mathpix', 'warnings' => []]],
            is_numeric($response->json('confidence')) ? (float) $response->json('confidence') : null,
            $response->json('request_id'),
            'mathpix',
        );
    }

    private function extractPdf(string $bytes): OcrResult
    {
        $response = $this->client()
            ->attach('file', $bytes, 'material.pdf')
            ->attach('options_json', json_encode([
                'math_inline_delimiters' => ['$', '$'],
                'metadata' => ['improve_mathpix' => false],
            ], JSON_THROW_ON_ERROR))
            ->post(config('services.mathpix.pdf_endpoint'));
        $this->assertSuccessful($response);

        $pdfId = $response->json('pdf_id');
        if (! is_string($pdfId) || $pdfId === '') {
            throw new OcrException('Mathpix did not return a document identifier.');
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this->client()->get(config('services.mathpix.pdf_endpoint').'/'.$pdfId);
            $this->assertSuccessful($status);
            if ($status->json('status') === 'completed') {
                $lines = $this->client()->get(config('services.mathpix.pdf_endpoint').'/'.$pdfId.'.lines.json');
                $this->assertSuccessful($lines);

                return new OcrResult($this->parsePdfPages($lines->body()), null, $pdfId, 'mathpix');
            }
            if ($status->json('status') === 'error') {
                throw new OcrException('Mathpix could not process this PDF.');
            }
            usleep(250_000);
        }

        throw new OcrException('Mathpix PDF processing timed out. Please retry.');
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

    /** @return array<int, array{page:int, content:string}> */
    private function parsePdfPages(string $body): array
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

        $normalized = collect($pages)->map(function (array $page, int $index): array {
            $content = collect($page['lines'] ?? [])
                ->map(fn ($line) => trim((string) ($line['text'] ?? $line['text_display'] ?? '')))
                ->filter()->implode("\n");

            return ['page' => (int) ($page['page'] ?? $index + 1), 'content' => $content];
        })->filter(fn (array $page) => $page['content'] !== '')->values()->all();

        if ($normalized === []) {
            throw new OcrException('Mathpix returned no readable page text.');
        }

        return $normalized;
    }
}
