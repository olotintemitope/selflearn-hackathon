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
    public function test_image_uses_mathpix_for_stem_visuals_and_handwriting(): void
    {
        $runner = new QueuedProcessRunner([]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => 'Photosynthesis converts light energy into chemical energy stored by plants.'],
        ], .94, 'image-request', 'mathpix', [], .91));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('image-bytes', 'image/png');

        $this->assertSame('mathpix', $result->extractor);
        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame(.94, $result->confidence);
        $this->assertSame(.91, $result->confidenceRate);
        $this->assertSame(1, $cloud->calls);
        $this->assertSame([], $runner->commands);
    }

    public function test_mathematical_image_routes_to_mathpix_and_preserves_latex(): void
    {
        $runner = new QueuedProcessRunner([]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => '$x^2 + 2x + 1 = 0$'],
        ], .98, 'request-1', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('equation-image', 'image/png');

        $this->assertSame('mathpix', $result->extractor);
        $this->assertSame('$x^2 + 2x + 1 = 0$', $result->pages[0]['content']);
        $this->assertSame(1, $cloud->calls);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_clock_and_time_pdf_pages_route_to_mathpix_even_without_equation_symbols(): void
    {
        $clockText = 'Read the clock by checking the long minute hand and the shorter hour hand.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'),
            new ProcessResult(true, $clockText),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            new ProcessResult(true, 'clock-page-image'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([
            ['page' => 1, 'content' => 'The minute hand shows thirty minutes.'],
        ], .92, 'clock-request', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF clock', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame(1, $cloud->calls);
        $this->assertSame('image/png', $cloud->mimeTypes[0]);
        $this->assertCount(5, $runner->commands);
    }

    public function test_pdf_page_batch_preserves_original_page_numbers_and_limits_tool_ranges(): void
    {
        $text = 'This page contains ordinary readable explanatory prose about a historical event.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 4'),
            new ProcessResult(true, $text."\f".$text."\f"),
            new ProcessResult(true, "Page 3 size: 612 x 792 pts\nPage 4 size: 612 x 792 pts"),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            $this->vectorLayouts([$text, $text]),
            new ProcessResult(true, "0.0001\n0.0001"),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extractBatch('%PDF four pages', 'application/pdf', 3, 4);

        $this->assertSame([3, 4], array_column($result->pages, 'page'));
        $this->assertSame(['pdftotext', 'pdftotext'], array_column($result->pages, 'extractor'));
        $this->assertSame(['-f', '3', '-l', '4'], array_slice($runner->commands[1], 4, 4));
        $this->assertContains('-f', $runner->commands[3]);
        $this->assertSame(0, $cloud->calls);
    }

    public function test_mixed_pdf_routes_each_page_once_and_preserves_page_provenance(): void
    {
        $pageOne = 'This selectable text page contains enough readable prose to use the local PDF text layer safely.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 2'),
            new ProcessResult(true, $pageOne."\fCalculate the force using mass and acceleration."),
            new ProcessResult(true, "Page 1 size: 612 x 792 pts\nPage 2 size: 612 x 792 pts"),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            ...$this->vectorInspection($pageOne),
            new ProcessResult(true, 'png-page-two'),
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

    public function test_embedded_image_inspection_is_batched_and_fails_safely(): void
    {
        config()->set('services.extraction.pdfimages_page_batch', 1);
        $text = 'This page contains enough ordinary selectable text to test batched image inspection.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 2'),
            new ProcessResult(true, $text."\f".$text."\f"),
            new ProcessResult(true, "Page 1 size: 612 x 792 pts\nPage 2 size: 612 x 792 pts"),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            new ProcessResult(false, '', 'inspection failed'),
        ]);
        $cloud = new FakeCloudOcr;

        try {
            (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF batched', 'application/pdf');
            $this->fail('Expected image inspection failure.');
        } catch (OcrException $exception) {
            $this->assertSame('Could not inspect PDF page layout to select a safe extractor.', $exception->getMessage());
            $this->assertSame(['-f', '1', '-l', '1'], array_slice($runner->commands[3], 2, 4));
            $this->assertSame(['-f', '2', '-l', '2'], array_slice($runner->commands[4], 2, 4));
            $this->assertSame(0, $cloud->calls);
        }
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

    public function test_readable_non_stem_pdf_uses_local_text_without_openai_or_mathpix(): void
    {
        $text = 'This page contains ordinary selectable text without any obvious STEM vocabulary.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            ...$this->vectorInspection($text),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF prose', 'application/pdf');

        $this->assertSame('pdftotext', $result->pages[0]['extractor']);
        $this->assertSame($text, $result->pages[0]['content']);
        $this->assertSame(0, $cloud->calls);
        $this->assertCount(7, $runner->commands);
    }

    public function test_stem_signal_in_readable_pdf_routes_page_to_mathpix(): void
    {
        $text = 'Calculate the velocity using the formula distance divided by time for the moving object.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            new ProcessResult(true, 'stem-page-image'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([['page' => 1, 'content' => 'Mathpix STEM result.']], .92, 'stem-request', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF stem', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame('stem-request', $result->pages[0]['provider_request_id']);
        $this->assertStringContainsString('STEM cues', $result->pages[0]['warnings'][0]);
        $this->assertSame(1, $cloud->calls);
        $this->assertSame(['image/png'], $cloud->mimeTypes);
    }

    public function test_physics_concept_phrase_routes_to_mathpix(): void
    {
        $text = 'Explain the law of conservation of energy as a pendulum swings from one side to the other.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            new ProcessResult(true, 'pendulum-page-image'),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF physics', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame(1, $cloud->calls);
    }

    public function test_non_background_embedded_visual_routes_readable_page_to_mathpix(): void
    {
        $text = 'This page contains ordinary explanatory text with no obvious mathematics or science terms.';
        $imageList = "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---\n  1  0 image  800  800 rgb  3  8  jpeg  no  7  0  120  120  30K  4.0%";
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'), new ProcessResult(true, $imageList),
            new ProcessResult(true, 'diagram-page-image'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([['page' => 1, 'content' => 'Visual content described by Mathpix.']], .96, 'visual-request', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF with visual', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame('visual-request', $result->pages[0]['provider_request_id']);
        $this->assertStringContainsString('non-text visual content', $result->pages[0]['warnings'][0]);
    }

    public function test_full_page_scan_with_usable_text_layer_stays_local_for_ordinary_prose(): void
    {
        $text = 'This scanned history page has a readable text layer and no STEM terms in its extracted prose.';
        $imageList = "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---\n  1  0 image  2550  3300 rgb  3  8  jpeg  no  7  0  300  300  300K  10.0%";
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'), new ProcessResult(true, $imageList),
            $this->renderPages(),
            ...$this->vectorInspection($text),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF OCR scan', 'application/pdf');

        $this->assertSame('pdftotext', $result->pages[0]['extractor']);
        $this->assertSame(0, $cloud->calls);
    }

    public function test_history_prose_with_ambiguous_words_does_not_trigger_mathpix(): void
    {
        $text = 'At the time, the current ruler used mass movement and energy supplies to strengthen regional trade.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            ...$this->vectorInspection($text),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF history prose', 'application/pdf');

        $this->assertSame('pdftotext', $result->pages[0]['extractor']);
        $this->assertSame(0, $cloud->calls);
    }

    public function test_low_quality_text_page_routes_to_mathpix_in_a_mixed_pdf(): void
    {
        $ordinary = 'This selectable page has enough ordinary prose to retain local text extraction safely.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 2'), new ProcessResult(true, $ordinary."\f\u{FFFD}###"),
            new ProcessResult(true, "Page 1 size: 612 x 792 pts\nPage 2 size: 612 x 792 pts"),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            ...$this->vectorInspection($ordinary),
            new ProcessResult(true, 'low-quality-page-image'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([['page' => 1, 'content' => 'Recovered scan text.']], .91, 'ocr-request', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF mixed quality', 'application/pdf');

        $this->assertSame(['pdftotext', 'mathpix'], array_column($result->pages, 'extractor'));
        $this->assertSame([1, 2], array_column($result->pages, 'page'));
        $this->assertSame(1, $cloud->calls);
    }

    public function test_full_page_scan_routes_only_visual_outlier_page_to_mathpix(): void
    {
        $pages = [
            'This ordinary history page describes a major political development in the country.',
            'This history page includes an important map showing the location of regional boundaries.',
            'This ordinary history page describes a major political development in the country.',
        ];
        $image = "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---\n";
        for ($page = 1; $page <= 3; $page++) {
            $image .= sprintf(" %d  0 image  2550  3300 rgb  3  8  jpeg  no  7  0  300  300  300K  10.0%%\n", $page);
        }
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 3'), new ProcessResult(true, implode("\f", $pages)."\f"),
            new ProcessResult(true, "Page 1 size: 612 x 792 pts\nPage 2 size: 612 x 792 pts\nPage 3 size: 612 x 792 pts"),
            new ProcessResult(true, $image),
            $this->renderPages(),
            $this->vectorLayouts($pages),
            new ProcessResult(true, "0.0003\n0.015\n0.0002"),
            new ProcessResult(true, 'map-page-image'),
        ]);
        $cloud = new FakeCloudOcr(new OcrResult([['page' => 1, 'content' => 'Map labels and boundary markings.']], .9, 'map-request', 'mathpix'));

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF scanned history with map', 'application/pdf');

        $this->assertSame(['pdftotext', 'mathpix', 'pdftotext'], array_column($result->pages, 'extractor'));
        $this->assertSame([1, 2, 3], array_column($result->pages, 'page'));
        $this->assertSame(1, $cloud->calls);
        $this->assertStringContainsString('non-text visual content', $result->pages[1]['warnings'][0]);
        $this->assertCount(1, array_filter($runner->commands, fn (array $command) => basename($command[0]) === 'pdftoppm'));
        $this->assertCount(2, array_filter($runner->commands, fn (array $command) => basename($command[0]) === 'magick'));
    }

    public function test_vector_diagram_signal_routes_to_mathpix_when_no_image_object_exists(): void
    {
        $text = 'This page explains a concept in ordinary text, while the diagram carries the important information.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            ...$this->vectorInspection($text, '0.0062'),
            new ProcessResult(true, 'vector-diagram-page'),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF vector diagram', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertStringContainsString('non-text visual content', $result->pages[0]['warnings'][0]);
        $this->assertSame(1, $cloud->calls);
    }

    public function test_visual_inspection_failure_routes_uncertain_page_to_mathpix(): void
    {
        $text = 'This ordinary looking page has readable prose but visual inspection cannot be trusted.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(),
            new ProcessResult(true, '<doc><page width="612" height="792"></page></doc>'),
            new ProcessResult(true, 'uncertain-page-image'),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF inspection failed', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertStringContainsString('non-text visual content', $result->pages[0]['warnings'][0]);
        $this->assertSame(1, $cloud->calls);
    }

    public function test_low_resolution_render_failure_routes_candidate_page_to_mathpix(): void
    {
        $text = 'This page has readable ordinary prose, but the visual page renderer failed to start.';
        $runner = new QueuedProcessRunner([
            new ProcessResult(true, 'Pages: 1'), new ProcessResult(true, $text."\f"),
            new ProcessResult(true, 'Page 1 size: 612 x 792 pts'),
            new ProcessResult(true, "page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio\n---"),
            $this->renderPages(false),
            new ProcessResult(true, 'uncertain-page-image'),
        ]);
        $cloud = new FakeCloudOcr;

        $result = (new MaterialExtractionRouter($runner, $cloud))->extract('%PDF render failure', 'application/pdf');

        $this->assertSame('mathpix', $result->pages[0]['extractor']);
        $this->assertSame(1, $cloud->calls);
    }

    /** @return list<ProcessResult> */
    private function vectorInspection(string $text, string $residual = '0.0001'): array
    {
        return [
            $this->vectorLayouts([$text]),
            new ProcessResult(true, $residual),
        ];
    }

    /** @param list<string> $texts */
    private function vectorLayouts(array $texts): ProcessResult
    {
        $pages = '';
        foreach ($texts as $text) {
            $words = preg_split('/\s+/u', trim($text)) ?: [];
            $wordBoxes = '';
            foreach ($words as $index => $word) {
                $x = 20 + ($index % 8) * 60;
                $y = 30 + intdiv($index, 8) * 18;
                $wordBoxes .= sprintf('<word xMin="%d" yMin="%d" xMax="%d" yMax="%d">%s</word>', $x, $y, $x + 40, $y + 12, htmlspecialchars($word, ENT_XML1));
            }
            $pages .= '<page width="612" height="792">'.$wordBoxes.'</page>';
        }

        return new ProcessResult(true, '<doc>'.$pages.'</doc>');
    }

    private function renderPages(bool $successful = true): ProcessResult
    {
        return new ProcessResult($successful, '');
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
