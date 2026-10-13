<?php

namespace Tests\Unit;

use App\Services\Learning\OpenAiPastPaperWrittenFeedbackGenerator;
use App\Services\Learning\PastPaperWrittenFeedbackException;
use App\Services\Learning\PastPaperWrittenFeedbackValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OpenAiPastPaperWrittenFeedbackGeneratorTest extends TestCase
{
    public function test_it_uses_strict_output_and_accepts_only_a_citation_for_the_question_source(): void
    {
        $this->configureProvider();
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'status' => 'ready',
                'feedback' => 'Your answer matches the unit stated in the paper.',
                'steps' => [
                    ['title' => 'Find the quantity', 'detail' => 'The question asks for the unit used to measure energy.'],
                    ['title' => 'Check the source', 'detail' => 'The paper states that energy is measured in joules.'],
                ],
                'source_chunk_ids' => [21],
            ])]],
        ]]])]);

        $result = (new OpenAiPastPaperWrittenFeedbackGenerator($http, new PastPaperWrittenFeedbackValidator))
            ->generate('Energy is measured in _____.', 'fill_gap', 'joules', [
                ['id' => 21, 'content' => 'Energy is measured in joules.', 'page' => 4, 'material_title' => 'Practice paper'],
            ]);

        $this->assertSame('ready', $result['status']);
        $this->assertSame([21], $result['source_chunk_ids']);
        $request = $http->recorded()->first()[0];
        $this->assertSame('json_schema', $request['text']['format']['type']);
        $this->assertTrue($request['text']['format']['strict']);
        $this->assertStringContainsString('untrusted data', $request['input'][0]['content']);
        $this->assertStringContainsString('fill_gap', $request['input'][1]['content']);
    }

    public function test_it_rejects_citations_outside_the_supplied_question_source(): void
    {
        $this->configureProvider();
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'status' => 'ready', 'feedback' => 'An unsupported explanation.',
                'steps' => [['title' => 'Step', 'detail' => 'Unsupported detail.']],
                'source_chunk_ids' => [22],
            ])]],
        ]]])]);

        $this->expectException(PastPaperWrittenFeedbackException::class);
        (new OpenAiPastPaperWrittenFeedbackGenerator($http, new PastPaperWrittenFeedbackValidator))
            ->generate('Explain the idea.', 'structured_response', 'Answer.', [
                ['id' => 21, 'content' => 'Only this page is evidence.', 'page' => 4, 'material_title' => 'Practice paper'],
            ]);
    }

    public function test_ambiguous_source_fixture_returns_no_steps_or_score(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/PastPaperFeedback/ambiguous-source.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->configureProvider();
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                ...$fixture['expected_feedback'],
            ])]],
        ]]])]);

        $result = (new OpenAiPastPaperWrittenFeedbackGenerator($http, new PastPaperWrittenFeedbackValidator))
            ->generate($fixture['question'], $fixture['question_type'], $fixture['learner_response'], [
                ['id' => 31, 'content' => $fixture['source_text'], 'page' => 2, 'material_title' => 'Practice paper'],
            ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame([], $result['steps']);
        $this->assertSame([], $result['source_chunk_ids']);
    }

    private function configureProvider(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
    }
}
