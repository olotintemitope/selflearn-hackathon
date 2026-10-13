<?php

namespace Tests\Unit;

use App\Services\Learning\OpenAiResponseDiagnostics;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAiResponseDiagnosticsTest extends TestCase
{
    public function test_quota_failures_are_not_retried_and_log_only_safe_provider_details(): void
    {
        Log::spy();
        $response = $this->response(429, [
            'error' => [
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
                'message' => 'private provider body must not be logged',
            ],
        ], ['Retry-After' => '60', 'x-request-id' => 'req_safe-123']);

        $message = OpenAiResponseDiagnostics::failureMessage($response, 'concept generation');

        $this->assertFalse(OpenAiResponseDiagnostics::shouldRetry($response->toException()));
        $this->assertStringContainsString('usage or spending limit', $message);
        $this->assertStringContainsString('code=insufficient_quota', $message);
        $this->assertStringContainsString('request_id=req_safe-123', $message);
        $this->assertStringNotContainsString('private provider body', $message);
        Log::shouldHaveReceived('warning')->once()->with('OpenAI API request failed', \Mockery::on(
            fn (array $context): bool => $context['error_code'] === 'insufficient_quota'
                && $context['retry_after_seconds'] === 60
                && $context['request_id'] === 'req_safe-123'
                && ! array_key_exists('message', $context)
        ));
    }

    public function test_transient_rate_limit_retries_only_within_bounded_retry_after(): void
    {
        $response = $this->response(429, [
            'error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded'],
        ], ['Retry-After' => '2']);

        $this->assertTrue(OpenAiResponseDiagnostics::shouldRetry($response->toException()));
        $this->assertSame(2000, OpenAiResponseDiagnostics::delayMilliseconds(1, $response->toException()));

        $longWait = $this->response(429, [
            'error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded'],
        ], ['Retry-After' => '45']);
        $this->assertFalse(OpenAiResponseDiagnostics::shouldRetry($longWait->toException()));
        $this->assertStringContainsString('Retry after 45 seconds', OpenAiResponseDiagnostics::failureMessage($longWait, 'concept generation'));
    }

    public function test_unknown_429_is_not_guessed_and_server_errors_remain_retryable(): void
    {
        $unknown429 = $this->response(429, ['error' => ['message' => 'do not expose this']], []);
        $this->assertFalse(OpenAiResponseDiagnostics::shouldRetry($unknown429->toException()));
        $this->assertStringContainsString('provider did not identify the limit', OpenAiResponseDiagnostics::failureMessage($unknown429, 'concept generation'));
        $this->assertStringNotContainsString('do not expose this', OpenAiResponseDiagnostics::failureMessage($unknown429, 'concept generation'));

        $serverError = $this->response(503, [], []);
        $this->assertTrue(OpenAiResponseDiagnostics::shouldRetry($serverError->toException()));
        $delay = OpenAiResponseDiagnostics::delayMilliseconds(1, $serverError->toException());
        $this->assertGreaterThanOrEqual(500, $delay);
        $this->assertLessThanOrEqual(600, $delay);
    }

    private function response(int $status, array $body, array $headers): Response
    {
        return new Response(new PsrResponse($status, $headers, json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
