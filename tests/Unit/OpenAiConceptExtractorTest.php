<?php

namespace Tests\Unit;

use App\Services\Learning\ConceptExtractionException;
use App\Services\Learning\ConceptGraphValidator;
use App\Services\Learning\OpenAiConceptExtractor;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAiConceptExtractorTest extends TestCase
{
    public function test_quota_failure_is_actionable_safe_and_not_retried(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        Log::spy();
        $http = new Factory;
        $http->fake(['*' => Factory::response([
            'error' => [
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
                'message' => 'private billing response content',
            ],
        ], 429, ['Retry-After' => '60', 'x-request-id' => 'req_concept-123'])]);

        try {
            (new OpenAiConceptExtractor($http, new ConceptGraphValidator))->extract([
                ['id' => 21, 'content' => 'A source concept.', 'page' => 1],
            ]);
            $this->fail('A provider quota error must fail concept generation.');
        } catch (ConceptExtractionException $exception) {
            $this->assertStringContainsString('usage or spending limit', $exception->getMessage());
            $this->assertStringContainsString('code=insufficient_quota', $exception->getMessage());
            $this->assertStringContainsString('retry_after_seconds=60', $exception->getMessage());
            $this->assertStringContainsString('request_id=req_concept-123', $exception->getMessage());
            $this->assertStringNotContainsString('private billing response content', $exception->getMessage());
        }

        $this->assertCount(1, $http->recorded());
        Log::shouldHaveReceived('warning')->once()->with('OpenAI API request failed', \Mockery::on(
            fn (array $context): bool => $context['operation'] === 'concept generation'
                && $context['error_code'] === 'insufficient_quota'
                && ! array_key_exists('message', $context)
        ));
    }
}
