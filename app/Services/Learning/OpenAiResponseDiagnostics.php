<?php

namespace App\Services\Learning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts only low-risk operational details from OpenAI error responses.
 * Never log the provider message, request body, or response body.
 */
final class OpenAiResponseDiagnostics
{
    private const QUOTA_CODES = [
        'insufficient_quota',
        'credit_balance_exhausted',
        'organization_usage_limit_exceeded',
        'organization_spend_limit_exceeded',
        'project_spend_limit_exceeded',
    ];

    private const RATE_LIMIT_CODES = [
        'rate_limit_exceeded',
        'rate_limit_error',
        'requests',
        'tokens',
        'too_many_requests',
    ];

    private const MAX_AUTOMATIC_RETRY_AFTER_SECONDS = 10;

    public static function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;
        $status = $response->status();

        if ($status >= 500) {
            return true;
        }

        if ($status !== 429) {
            return false;
        }

        $details = self::details($response);
        if (self::isQuotaFailure($details)) {
            return false;
        }

        if (! self::isRateLimitFailure($details)) {
            return false;
        }

        return $details['retry_after_seconds'] === null
            || $details['retry_after_seconds'] <= self::MAX_AUTOMATIC_RETRY_AFTER_SECONDS;
    }

    public static function delayMilliseconds(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            $retryAfter = self::details($exception->response)['retry_after_seconds'];
            if ($retryAfter !== null) {
                return $retryAfter * 1000;
            }
        }

        $baseDelay = min(2000, max(1, $attempt) * 500);

        return $baseDelay + random_int(0, (int) ceil($baseDelay * 0.2));
    }

    public static function failureMessage(Response $response, string $operation): string
    {
        $details = self::details($response);
        Log::warning('OpenAI API request failed', ['operation' => $operation, ...$details]);

        $summary = 'HTTP '.$details['http_status'];
        if ($details['error_type'] !== null) {
            $summary .= ', type='.$details['error_type'];
        }
        if ($details['error_code'] !== null) {
            $summary .= ', code='.$details['error_code'];
        }
        if ($details['retry_after_seconds'] !== null) {
            $summary .= ', retry_after_seconds='.$details['retry_after_seconds'];
        }
        if ($details['request_id'] !== null) {
            $summary .= ', request_id='.$details['request_id'];
        }

        if (self::isQuotaFailure($details)) {
            return 'OpenAI blocked '.$operation.' because of an API usage or spending limit ('.$summary.'). Check project or organization usage and billing limits before retrying.';
        }

        if ($details['http_status'] === 429 && self::isRateLimitFailure($details)) {
            $wait = $details['retry_after_seconds'] ?? 1;

            return 'OpenAI rate-limited '.$operation.' ('.$summary.'). Retry after '.$wait.' seconds.';
        }

        if ($details['http_status'] === 429) {
            return 'OpenAI returned HTTP 429 for '.$operation.' ('.$summary.'). The provider did not identify the limit; check project usage and retry guidance before trying again.';
        }

        return 'OpenAI could not complete '.$operation.' ('.$summary.'). Try again later or check the provider request configuration.';
    }

    /** @return array{http_status:int,error_type:?string,error_code:?string,retry_after_seconds:?int,request_id:?string} */
    private static function details(Response $response): array
    {
        return [
            'http_status' => $response->status(),
            'error_type' => self::safeToken($response->json('error.type')),
            'error_code' => self::safeToken($response->json('error.code')),
            'retry_after_seconds' => self::retryAfterSeconds($response->header('Retry-After')),
            'request_id' => self::safeToken($response->header('x-request-id')),
        ];
    }

    private static function retryAfterSeconds(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (ctype_digit($value)) {
            return min(86400, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return min(86400, max(0, $timestamp - time()));
    }

    private static function safeToken(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $token = preg_replace('/[^A-Za-z0-9_.:-]/', '', $value);

        return $token === '' ? null : substr($token, 0, 80);
    }

    /** @param array<string, mixed> $details */
    private static function isQuotaFailure(array $details): bool
    {
        return in_array($details['error_code'], self::QUOTA_CODES, true)
            || in_array($details['error_type'], self::QUOTA_CODES, true);
    }

    /** @param array<string, mixed> $details */
    private static function isRateLimitFailure(array $details): bool
    {
        return in_array($details['error_code'], self::RATE_LIMIT_CODES, true)
            || in_array($details['error_type'], self::RATE_LIMIT_CODES, true);
    }
}
