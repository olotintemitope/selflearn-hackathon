<?php

namespace Tests\Unit;

use App\Services\Ocr\MathpixOcrClient;
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
        Http::fake(['api.mathpix.com/v3/text' => Http::response(['text' => 'Ohm law', 'confidence' => .9, 'request_id' => 'x'])]);

        $result = app(MathpixOcrClient::class)->extract('binary-image', 'image/png');

        $this->assertSame('Ohm law', $result->pages[0]['content']);
        Http::assertSent(fn (Request $request) => str_starts_with($request['src'], 'data:image/png;base64,')
            && base64_decode(substr($request['src'], strlen('data:image/png;base64,'))) === 'binary-image'
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
}
