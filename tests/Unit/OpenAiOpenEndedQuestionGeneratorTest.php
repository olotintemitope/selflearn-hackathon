<?php

namespace Tests\Unit;

use App\Services\Learning\OpenAiOpenEndedQuestionGenerator;
use App\Services\Learning\OpenEndedQuestionResponseValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiOpenEndedQuestionGeneratorTest extends TestCase
{
    public function test_it_generates_exam_style_written_practice_without_quizzing_ocr_quality(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $http = new Factory;
        $http->fake(['*' => Factory::response(['output' => [[
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'prompt' => 'Explain how gravitational potential energy decreases and kinetic energy increases as a pendulum swings from its highest point to its lowest point.',
                'knowledge_basis' => 'supplementary',
                'solution_steps' => [
                    ['title' => 'State the energy principle', 'content' => 'Total mechanical energy stays constant.'],
                    ['title' => 'Explain the changes', 'content' => 'As height falls, gravitational potential energy changes into kinetic energy.'],
                ],
                'criteria' => [
                    ['id' => 'C1', 'description' => 'Describes gravitational potential energy decreasing.', 'prompt_reference' => 'gravitational potential energy decreases', 'points' => 2, 'source_chunk_ids' => [12]],
                    ['id' => 'C2', 'description' => 'Describes kinetic energy increasing.', 'prompt_reference' => 'kinetic energy increases', 'points' => 3, 'source_chunk_ids' => [12]],
                ],
            ])]],
        ]]])]);

        $result = (new OpenAiOpenEndedQuestionGenerator($http, new OpenEndedQuestionResponseValidator))->generate('Conservation of energy', [
            ['id' => 12, 'content' => 'Law of conservation of energy; swinging pendulum.', 'page' => 1, 'material_title' => 'Physics scan'],
        ]);

        $this->assertSame('supplementary', $result['knowledge_basis']);
        $request = $http->recorded()->first()[0];
        $this->assertSame('grounded-written-question-v8', json_decode($request['input'][1]['content'], true)['prompt_version']);
        $this->assertStringContainsString('Never ask the learner to judge whether a scan, OCR, extract', $request['input'][0]['content']);
        $this->assertStringContainsString('show the arithmetic, and state the final answer', $request['input'][0]['content']);
        $this->assertStringContainsString('question itself must explicitly ask for that exact detail', $request['input'][0]['content']);
        $this->assertCount(2, $result['solution_steps']);
        $this->assertSame(['source_supported', 'supplementary'], $request['text']['format']['schema']['properties']['knowledge_basis']['enum']);
        $this->assertContains('prompt_reference', $request['text']['format']['schema']['properties']['criteria']['items']['required']);
    }

    public function test_it_retries_once_when_a_rubric_requirement_is_missing_from_the_question(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $invalid = [
            'prompt' => 'Explain the region’s development and how earlier political systems shaped later society.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Identify regional groups', 'content' => 'The Hausa, Yoruba, and Igbo were especially influential.'],
                ['title' => 'Explain continuity', 'content' => 'Earlier political systems shaped later society.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Identifies the Hausa, Yoruba, and Igbo as major groups.', 'prompt_reference' => 'identifies the Hausa, Yoruba, and Igbo', 'points' => 2, 'source_chunk_ids' => [12]],
                ['id' => 'C2', 'description' => 'Explains how political systems shaped society.', 'prompt_reference' => 'how earlier political systems shaped later society', 'points' => 3, 'source_chunk_ids' => [12]],
            ],
        ];
        $corrected = $invalid;
        $corrected['prompt'] = 'Explain the region’s development, identify the Hausa, Yoruba, and Igbo as major groups, and explain how earlier political systems shaped later society.';
        $corrected['criteria'][0]['prompt_reference'] = 'identify the Hausa, Yoruba, and Igbo as major groups';
        Http::fakeSequence('api.openai.test/*')
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($invalid)]]]]])
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($corrected)]]]]]);

        $result = (new OpenAiOpenEndedQuestionGenerator(app(Factory::class), new OpenEndedQuestionResponseValidator))
            ->generate('Regional histories', [['id' => 12, 'content' => 'The Hausa, Yoruba, and Igbo were major groups. Earlier political systems shaped society.', 'page' => 3, 'material_title' => 'History']]);

        $this->assertStringContainsString('identify the Hausa, Yoruba, and Igbo as major groups', $result['prompt']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['input'][0]['content'], 'previous draft contained a hidden rubric requirement'));
    }

    public function test_it_falls_back_to_a_source_based_question_after_two_unfair_drafts(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $unfair = [
            'prompt' => 'Explain the development of regional identities.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Describe history', 'content' => 'Regional histories differed.'],
                ['title' => 'Explain influence', 'content' => 'They shaped later society.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Names the Hausa, Yoruba, and Igbo.', 'prompt_reference' => 'Names the Hausa, Yoruba, and Igbo', 'points' => 2, 'source_chunk_ids' => [12]],
                ['id' => 'C2', 'description' => 'Explains the regional influence.', 'prompt_reference' => 'Explains the regional influence', 'points' => 3, 'source_chunk_ids' => [12]],
            ],
        ];
        Http::fakeSequence('api.openai.test/*')
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($unfair)]]]]])
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($unfair)]]]]]);

        $result = (new OpenAiOpenEndedQuestionGenerator(app(Factory::class), new OpenEndedQuestionResponseValidator))
            ->generate('Islam and the Sokoto Caliphate', [[
                'id' => 12,
                'content' => 'Like so many other modern African states, Nigeria was created by European imperialism. Islam spread mainly in the north and later southwestern Nigeria. The Sokoto Caliphate brought much of the north under one Islamic government.',
                'page' => 3,
                'material_title' => 'History',
            ]]);

        $this->assertSame('source_supported', $result['knowledge_basis']);
        $this->assertStringContainsString('two facts from your materials', $result['prompt']);
        $this->assertCount(3, $result['solution_steps']);
        $this->assertStringContainsString('One detail in the material is:', $result['solution_steps'][0]['content']);
        $this->assertStringContainsString('Sokoto Caliphate', $result['solution_steps'][0]['content']);
        $this->assertStringContainsString('Islam spread mainly', $result['solution_steps'][1]['content']);
        $this->assertStringNotContainsString('European imperialism', $result['solution_steps'][0]['content'].$result['solution_steps'][1]['content']);
        $this->assertSame([12], $result['source_chunk_ids']);
        Http::assertSentCount(2);
    }

    public function test_clock_fallback_teaches_with_clear_examples_without_showing_internal_source_notes(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $unfair = [
            'prompt' => 'Explain reading times from clock faces.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Name the answer', 'content' => 'One clock reads a particular time.'],
                ['title' => 'Explain the answer', 'content' => 'Another clock reads another time.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Names two clock times.', 'prompt_reference' => 'Names two clock times', 'points' => 2, 'source_chunk_ids' => [12]],
                ['id' => 'C2', 'description' => 'Connects both times to reading clocks.', 'prompt_reference' => 'Connects both times to reading clocks', 'points' => 3, 'source_chunk_ids' => [12]],
            ],
        ];
        Http::fakeSequence('api.openai.test/*')
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($unfair)]]]]])
            ->push(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($unfair)]]]]]);

        $result = (new OpenAiOpenEndedQuestionGenerator(app(Factory::class), new OpenEndedQuestionResponseValidator))
            ->generate('Reading times from clock faces', [[
                'id' => 12,
                'content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 1 shows 03:00; Clock face 2 shows 05:30; Clock face 3 shows 08:00.',
                'page' => 1,
                'material_title' => 'Clock practice',
            ]]);

        $solution = collect($result['solution_steps'])->pluck('content')->implode(' ');
        $this->assertCount(3, $result['solution_steps']);
        $this->assertStringContainsString('Clock face 1 shows 03:00', $solution);
        $this->assertStringContainsString('three o\'clock', $solution);
        $this->assertStringContainsString('Clock face 2 shows 05:30', $solution);
        $this->assertStringContainsString('half past five', $solution);
        $this->assertStringNotContainsString('Learner-confirmed', $solution);
        $this->assertStringNotContainsString('Mathpix', $solution);
    }
}
