<?php

namespace Tests\Unit;

use App\Services\Ocr\CloudOcrClient;
use App\Services\Ocr\MaterialExtractionRouter;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrResult;
use App\Services\Ocr\ProcessResult;
use App\Services\Ocr\ProcessRunner;
use Tests\TestCase;

class MaterialExtractionRouterTest extends TestCase
{
    public function test_printed_prose_image_uses_local_tesseract_without_cloud_call(): void
    {
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Photosynthesis converts light energy into chemical energy stored by plants.'),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('image-bytes', 'image/png');

        $this->assertSame('tesseract', $result->extractor);
        $this->assertSame('tesseract', $result->pages[0]['extractor']);
        $this->assertSame(0, $cloud->calls);
        $this->assertSame('image-bytes', $runner->inputs[0]);
    }

    public function test_mathematical_image_routes_to_mathpix_and_preserves_latex(): void
    {
        $runner = new QueuedProcessRunner([new ProcessResult(true, 'Solve x^2 + 2x + 1 = 0')]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => '$x^2 + 2x + 1 = 0$'],
        ], .98, 'request-1', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('equation-image', 'image/png');

        $this->assertSame('mathpix', $result->extractor);
        $this->assertSame('$x^2 + 2x + 1 = 0$', $result->pages[0]['content']);
        $this->assertSame(1, $cloud->calls);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_mixed_pdf_routes_each_page_once_and_preserves_page_provenance(): void
    {
        $pageOne = 'This selectable text page contains enough readable prose to use the local PDF text layer safely.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 2'),
            new ProcessResult(true, $pageOne."\f\f"),
            new ProcessResult(true, 'png-page-two'),
            new ProcessResult(true, 'x^2 + y^2 = z^2'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => '$x^2 + y^2 = z^2$'],
        ], .95, 'request-2', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF mixed', 'application/pdf');

        $this->assertSame('mixed', $result->extractor);
        $this->assertCount(2, $result->pages);
        $this->assertSame([1, 2], array_column($result->pages, 'page'));
        $this->assertSame(['pdftotext', 'mathpix'], array_column($result->pages, 'extractor'));
        $this->assertSame($pageOne, $result->pages[0]['content']);
        $this->assertSame('$x^2 + y^2 = z^2$', $result->pages[1]['content']);
        $this->assertSame('request-2', $result->pages[1]['provider_request_id']);
        $this->assertSame(.95, $result->pages[1]['confidence']);
        $this->assertSame(1, $cloud->calls);
        $this->assertSame('image/png', $cloud->mimeTypes[0]);
    }

    public function test_failed_pdf_text_extraction_falls_back_to_mathpix_document_flow(): void
    {
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'),
            new ProcessResult(false, '', 'invalid PDF'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => 'Recovered document text.'],
        ], null, 'pdf-1', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF broken', 'application/pdf');

        $this->assertSame('mathpix', $result->extractor);
        $this->assertSame(['application/pdf'], $cloud->mimeTypes);
        $this->assertStringContainsString('failed', $result->warnings[0]);
    }

    public function test_oversized_pdf_is_rejected_before_extraction_or_cloud_upload(): void
    {
        config()->set('services.extraction.max_pdf_pages', 50);
        $runner = new QueuedProcessRunner([new ProcessResult(true, 'Pages: 51')]);
        $cloud = new FakeCloudOcr;

        $this->expectException(OcrException::class);
        $this->expectExceptionMessage('This PDF exceeds the configured page limit.');

        try {
            (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF large', 'application/pdf');
        } finally {
            $this->assertCount(1, $runner->commands);
            $this->assertSame(0, $cloud->calls);
        }
    }
}

final class QueuedProcessRunner implements ProcessRunner
{
    public array $commands = [];

    public array $inputs = [];

    public function __construct(private array $results) {}

    public function run(array $command, ?string $input = null, int $timeout = 30): ProcessResult
    {
        $this->commands[] = $command;
        $this->inputs[] = $input;

        return array_shift($this->results) ?? new ProcessResult(false, '', 'No fake result queued.');
    }
}

final class FakeCloudOcr implements CloudOcrClient
{
    public int $calls = 0;

    public array $mimeTypes = [];

    public function __construct(private ?OcrResult $result = null) {}

    public function extract(string $bytes, string $mimeType): OcrResult
    {
        $this->calls++;
        $this->mimeTypes[] = $mimeType;

        return $this->result ?? new OcrResult([['page' => 1, 'content' => 'Cloud text']], null, 'request', 'mathpix');
    }
}
