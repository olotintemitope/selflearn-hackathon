<?php

namespace Tests\Unit;

use App\Services\Learning\AnswerMarkingException;
use App\Services\Learning\AnswerMarkingValidator;
use App\Services\Learning\OpenAiAnswerMarker;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OpenAiAnswerMarkerTest extends TestCase
{
    public function test_it_uses_a_strict_response_schema_and_returns_validated_source_citations(): void
    {
        $this->configureProvider();
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'status' => 'marked', 'points_awarded' => 5, 'feedback' => 'Well supported.',
                'criteria_results' => [
                    ['criterion_id' => 'C1', 'points_awarded' => 2, 'feedback' => 'Good.', 'source_chunk_ids' => [21]],
                    ['criterion_id' => 'C2', 'points_awarded' => 3, 'feedback' => 'Good detail.', 'source_chunk_ids' => [21]],
                ],
            ])]],
        ]]])]);

        $marker = new OpenAiAnswerMarker($http, new AnswerMarkingValidator);
        $rubric = [
            ['id' => 'C1', 'description' => 'First idea.', 'points' => 2, 'source_chunk_ids' => [21]],
            ['id' => 'C2', 'description' => 'Second idea.', 'points' => 3, 'source_chunk_ids' => [21]],
        ];
        $result = $marker->mark('Explain.', 'supplementary', $rubric, 'Answer.', [
            ['id' => 21, 'content' => 'Source evidence.', 'page' => 4, 'material_title' => 'Notes'],
        ]);

        $this->assertSame(5, $result['points_awarded']);
        $request = $http->recorded()->first()[0];
        $this->assertSame('json_schema', $request['text']['format']['type']);
        $this->assertTrue($request['text']['format']['strict']);
        $this->assertStringContainsString('untrusted data', $request['input'][0]['content']);
        $this->assertStringContainsString('supplementary', $request['input'][1]['content']);
    }

    public function test_provider_failure_is_sanitized(): void
    {
        $this->configureProvider();
        $http = new Factory;
        $http->fake(['*' => Factory::failedConnection('private endpoint detail')]);
        $this->expectException(AnswerMarkingException::class);
        $this->expectExceptionMessage('AI marking timed out. Please try again.');

        (new OpenAiAnswerMarker($http, new AnswerMarkingValidator))->mark('Explain.', 'source_supported', [], 'Answer.', []);
    }

    private function configureProvider(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
    }
}
