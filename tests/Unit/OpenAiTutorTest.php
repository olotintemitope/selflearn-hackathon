<?php

namespace Tests\Unit;

use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\TutorException;
use App\Services\Learning\TutorResponseValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OpenAiTutorTest extends TestCase
{
    public function test_connection_failure_becomes_safe_timeout_state(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $http = new Factory;
        $http->fake(fn () => Factory::failedConnection('secret transport detail'));

        $this->expectException(TutorException::class);
        $this->expectExceptionMessage('The tutor timed out. Please try again.');

        (new OpenAiTutor($http, new TutorResponseValidator))->answer('Explain gravity', [
            ['id' => 1, 'content' => 'Gravity attracts masses.'],
        ]);
    }

    public function test_tutor_receives_recent_learning_conversation_for_follow_up_teaching(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'answer' => 'Step 1: Energy moves into storage.',
                'citations' => [['chunk_id' => 1, 'excerpt' => 'stores energy']],
                'supplementary_sections' => [], 'insufficient_evidence' => false, 'understanding_check' => 'not_applicable',
            ])]],
        ]]])]);
        $history = [
            ['role' => 'assistant', 'content' => 'What happens to the energy?'],
            ['role' => 'user', 'content' => 'It is stored as chemical energy.'],
        ];

        (new OpenAiTutor($http, new TutorResponseValidator))->answer('Why does that matter?', [
            ['id' => 1, 'content' => 'Photosynthesis stores energy in glucose.'],
        ], 'ha', $history);

        $request = $http->recorded()->first()[0];
        $input = json_decode($request['input'][1]['content'], true);
        $this->assertSame($history, $input['conversation_history']);
        $this->assertSame('Hausa', $input['desired_language']);
        $this->assertStringContainsString('Teach in three short numbered steps', $request['input'][0]['content']);
        $this->assertStringContainsString('Write all answer and supplementary_sections content in the desired language', $request['input'][0]['content']);
        $this->assertStringContainsString('Keep citation excerpts verbatim in their original source language', $request['input'][0]['content']);
        $this->assertSame('grounded-tutor-v8', $input['prompt_version']);
        $this->assertStringContainsString('That language preference takes priority over the language of the learner question and every previous message', $request['input'][0]['content']);
        $this->assertContains('understanding_check', $request['text']['format']['schema']['required']);
    }
}
