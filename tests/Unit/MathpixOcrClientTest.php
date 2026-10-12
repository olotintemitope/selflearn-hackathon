<?php

namespace Tests\Unit;

use App\Services\Ocr\MathpixOcrClient;
use App\Services\Ocr\OcrException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MathpixOcrClientTest extends TestCase
{
    public function test_image_bytes_are_sent_as_base64_not_local_url(): void
    {
        config()->set('services.mathpix', [
            'app_id' => 'test-id', 'app_key' => 'test-key',
            'image_endpoint' => 'https://api.mathpix.com/v3/text',
            'pdf_endpoint' => 'https://api.mathpix.com/v3/pdf',
        ]);
        Http::fake(['api.mathpix.com/v3/text' => Http::response(['text' => 'Ohm law', 'confidence' => .9, 'confidence_rate' => .92, 'request_id' => 'x'])]);

        $result = app(MathpixOcrClient::class)->extract('binary-image', 'image/png');

        $this->assertSame('Ohm law', $result->pages[0]['content']);
        $this->assertSame(.9, $result->confidence);
        $this->assertSame(.92, $result->confidenceRate);
        $this->assertSame(.92, $result->pages[0]['confidence_rate']);
        Http::assertSent(fn (Request $request) => str_starts_with($request['src'], 'data:image/png;base64,')
            && base64_decode(substr($request['src'], strlen('data:image/png;base64,'))) === 'binary-image'
            && $request['enable_document_layout'] === true
            && $request->hasHeader('app_id', 'test-id')
        );
    }

    public function test_pdf_bytes_are_uploaded_and_lines_become_page_linked_chunks(): void
    {
        config()->set('services.mathpix', [
            'app_id' => 'test-id', 'app_key' => 'test-key',
            'image_endpoint' => 'https://api.mathpix.com/v3/text',
            'pdf_endpoint' => 'https://api.mathpix.com/v3/pdf',
        ]);
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['pdf_id' => 'pdf-1']);
            }
            if (str_ends_with($request->url(), '.lines.json')) {
                return Http::response(['pages' => [
                    ['page' => 1, 'lines' => [['text' => 'Voltage is potential difference.']]],
                    ['page' => 2, 'lines' => [['text' => 'Current is charge flow.']]],
                ]]);
            }

            return Http::response(['status' => 'completed']);
        });

        $result = app(MathpixOcrClient::class)->extract('%PDF bytes', 'application/pdf');

        $this->assertSame([1, 2], array_column($result->pages, 'page'));
        $this->assertSame('Current is charge flow.', $result->pages[1]['content']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->body(), '%PDF bytes')
            && str_contains($request->body(), 'improve_mathpix')
        );
    }

    public function test_pdf_processing_is_polled_until_mathpix_finishes(): void
    {
        config()->set('services.mathpix', [
            'app_id' => 'test-id', 'app_key' => 'test-key',
            'image_endpoint' => 'https://api.mathpix.com/v3/text',
            'pdf_endpoint' => 'https://api.mathpix.com/v3/pdf',
            'pdf_max_poll_attempts' => 5,
            'pdf_poll_interval_ms' => 0,
        ]);
        $statusCalls = 0;
        Http::fake(function (Request $request) use (&$statusCalls) {
            if ($request->method() === 'POST') {
                return Http::response(['pdf_id' => 'pdf-slow']);
            }
            if (str_ends_with($request->url(), '.lines.json')) {
                return Http::response(['pages' => [['page' => 1, 'lines' => [['text' => 'A longer scan completed.']]]]]);
            }

            $statusCalls++;

            return Http::response(['status' => $statusCalls < 4 ? 'processing' : 'completed']);
        });

        $result = app(MathpixOcrClient::class)->extract('%PDF slow scan', 'application/pdf');

        $this->assertSame('A longer scan completed.', $result->pages[0]['content']);
        $this->assertSame(4, $statusCalls);
    }

    public function test_pdf_processing_times_out_after_the_configured_poll_limit(): void
    {
        config()->set('services.mathpix', [
            'app_id' => 'test-id', 'app_key' => 'test-key',
            'image_endpoint' => 'https://api.mathpix.com/v3/text',
            'pdf_endpoint' => 'https://api.mathpix.com/v3/pdf',
            'pdf_max_poll_attempts' => 2,
            'pdf_poll_interval_ms' => 0,
        ]);
        $statusCalls = 0;
        Http::fake(function (Request $request) use (&$statusCalls) {
            if ($request->method() === 'POST') {
                return Http::response(['pdf_id' => 'pdf-timeout']);
            }
            $statusCalls++;

            return Http::response(['status' => 'processing']);
        });

        try {
            app(MathpixOcrClient::class)->extract('%PDF slow scan', 'application/pdf');
            $this->fail('Expected Mathpix polling to time out.');
        } catch (OcrException $exception) {
            $this->assertSame('Mathpix PDF processing timed out. Please retry.', $exception->getMessage());
            $this->assertSame(2, $statusCalls);
        }
    }

    public function test_connection_failure_is_sanitized(): void
    {
        config()->set('services.mathpix', [
            'app_id' => 'test-id', 'app_key' => 'test-key',
            'image_endpoint' => 'https://api.mathpix.com/v3/text',
            'pdf_endpoint' => 'https://api.mathpix.com/v3/pdf',
        ]);
        Http::fake(fn () => Http::failedConnection('secret transport detail'));

        $this->expectException(OcrException::class);
        $this->expectExceptionMessage('Mathpix could not be reached. Please retry.');

        app(MathpixOcrClient::class)->extract('binary-image', 'image/png');
    }
}
