<?php

namespace Tests\Unit;

use App\Services\Learning\OpenEndedQuestionGenerationException;
use App\Services\Learning\OpenEndedQuestionResponseValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenEndedQuestionResponseValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_a_five_point_rubric_only_when_sources_are_allowed(): void
    {
        $data = [
            'prompt' => 'Explain the process shown in your notes. Identify the input and explain the outcome.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Identify the input', 'content' => 'Use the input described in the notes.'],
                ['title' => 'Explain the outcome', 'content' => 'Connect the input to the stated outcome.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Identifies the input.', 'prompt_reference' => 'Identify the input', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains the outcome.', 'prompt_reference' => 'explain the outcome', 'points' => 3, 'source_chunk_ids' => [9]],
            ],
        ];

        $result = (new OpenEndedQuestionResponseValidator)->validate($data, [8, 9]);

        $this->assertSame(5, $result['max_points']);
        $this->assertSame('source_supported', $result['knowledge_basis']);
        $emDash = "\u{2014}";
        $data['prompt'] = 'Explain the process '.$emDash.' identify the input and explain the outcome.';
        $data['criteria'][0]['description'] = 'Identifies the input '.$emDash.' accurately.';
        $cleaned = (new OpenEndedQuestionResponseValidator)->validate($data, [8, 9]);
        $this->assertSame('Explain the process: identify the input and explain the outcome.', $cleaned['prompt']);
        $this->assertSame('Identifies the input: accurately.', $cleaned['rubric'][0]['description']);
        $this->assertSame([8, 9], $result['source_chunk_ids']);
    }

    #[Test]
    public function it_rejects_unknown_sources_and_rubrics_that_do_not_total_five(): void
    {
        $validator = new OpenEndedQuestionResponseValidator;
        $unknownSource = [
            'prompt' => 'Explain.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Identify the idea', 'content' => 'Use the cited explanation.'],
                ['title' => 'State the result', 'content' => 'Connect the result to the idea.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'First.', 'prompt_reference' => 'Identify the input', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Second.', 'prompt_reference' => 'explain the outcome', 'points' => 3, 'source_chunk_ids' => [99]],
            ],
        ];
        try {
            $validator->validate($unknownSource, [8, 9]);
            $this->fail('Expected an unknown citation to be rejected.');
        } catch (OpenEndedQuestionGenerationException) {
            $this->assertTrue(true);
        }

        $invalidTotal = $unknownSource;
        $invalidTotal['criteria'][1]['source_chunk_ids'] = [9];
        $invalidTotal['criteria'][1]['points'] = 2;
        $this->expectException(OpenEndedQuestionGenerationException::class);
        $validator->validate($invalidTotal, [8, 9]);
    }

    #[Test]
    public function it_accepts_a_supplementary_written_question_but_rejects_an_unknown_basis(): void
    {
        $data = [
            'prompt' => 'Explain how gravitational potential energy decreases and kinetic energy increases as a pendulum moves from its highest point to its lowest point.',
            'knowledge_basis' => 'supplementary',
            'solution_steps' => [
                ['title' => 'Use conservation of energy', 'content' => 'The total mechanical energy remains constant.'],
                ['title' => 'Describe the transfer', 'content' => 'As the bob falls, gravitational potential energy changes into kinetic energy.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Describes the decrease in gravitational potential energy.', 'prompt_reference' => 'gravitational potential energy decreases', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Describes the increase in kinetic energy.', 'prompt_reference' => 'kinetic energy increases', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];

        $result = (new OpenEndedQuestionResponseValidator)->validate($data, [8]);
        $this->assertSame('supplementary', $result['knowledge_basis']);

        $data['knowledge_basis'] = 'guess';
        $this->expectException(OpenEndedQuestionGenerationException::class);
        (new OpenEndedQuestionResponseValidator)->validate($data, [8]);
    }

    #[Test]
    public function exam_mode_rejects_supplementary_written_questions(): void
    {
        $data = [
            'prompt' => 'Explain how potential energy changes as a pendulum swings.',
            'knowledge_basis' => 'supplementary',
            'solution_steps' => [
                ['title' => 'Identify the change', 'content' => 'Potential energy changes as height changes.'],
                ['title' => 'Connect the movement', 'content' => 'The pendulum moves through different heights.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Explains potential energy changes.', 'prompt_reference' => 'Explain how potential energy changes', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Connects the energy change to the swing.', 'prompt_reference' => 'as a pendulum swings', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];

        $this->expectException(OpenEndedQuestionGenerationException::class);
        (new OpenEndedQuestionResponseValidator)->validate($data, [8], [], 'exam');
    }

    #[Test]
    public function it_marks_clock_hand_explanations_supplementary_when_only_times_were_confirmed(): void
    {
        $data = [
            'prompt' => 'Explain how the minute hand and hour hand show half past.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Read the minute hand', 'content' => 'At half past it points to 6.'],
                ['title' => 'Read the hour hand', 'content' => 'It has moved between hour numbers.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Explains the minute hand position.', 'prompt_reference' => 'minute hand', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains the hour hand position.', 'prompt_reference' => 'hour hand', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];
        $evidence = [['id' => 8, 'content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 2 shows 05:30.']];

        $result = (new OpenEndedQuestionResponseValidator)->validate($data, [8], $evidence);

        $this->assertSame('supplementary', $result['knowledge_basis']);
    }

    #[Test]
    public function it_rejects_rubric_requirements_that_are_absent_from_the_question(): void
    {
        $data = [
            'prompt' => 'Explain the two stages of political formation.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Describe conquest', 'content' => 'The conquest ended in 1903.'],
                ['title' => 'Describe amalgamation', 'content' => 'Northern and southern Nigeria were joined in 1914.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'Names the Colony and Protectorate of Nigeria.', 'prompt_reference' => 'Names the Colony and Protectorate of Nigeria', 'points' => 1, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains the two stages.', 'prompt_reference' => 'two stages of political formation', 'points' => 4, 'source_chunk_ids' => [8]],
            ],
        ];

        $this->expectException(OpenEndedQuestionGenerationException::class);
        (new OpenEndedQuestionResponseValidator)->validate($data, [8]);
    }

    #[Test]
    public function it_rejects_a_hidden_date_even_when_the_rubric_reference_is_present(): void
    {
        $data = [
            'prompt' => 'Explain the British conquest and 1914 amalgamation.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Explain conquest', 'content' => 'British conquest was completed in 1903.'],
                ['title' => 'Explain amalgamation', 'content' => 'Northern and southern Nigeria were joined in 1914.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'States that conquest was completed in 1903.', 'prompt_reference' => 'British conquest', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains the 1914 amalgamation.', 'prompt_reference' => '1914 amalgamation', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];

        $this->expectException(OpenEndedQuestionGenerationException::class);
        (new OpenEndedQuestionResponseValidator)->validate($data, [8]);
    }

    #[Test]
    public function it_makes_a_source_supported_rubric_date_explicit_in_the_question(): void
    {
        $data = [
            'prompt' => 'Explain the British conquest and 1914 amalgamation.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Conquest', 'content' => 'Conquest was completed in 1903.'],
                ['title' => 'Amalgamation', 'content' => 'Northern and southern Nigeria were joined in 1914.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'States that conquest was completed in 1903.', 'prompt_reference' => 'British conquest', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains the 1914 amalgamation.', 'prompt_reference' => '1914 amalgamation', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];

        $result = (new OpenEndedQuestionResponseValidator)->validate($data, [8], [
            ['id' => 8, 'content' => 'British conquest was completed in 1903. The 1914 amalgamation united northern and southern Nigeria.'],
        ]);

        $this->assertStringContainsString('Also state the year associated with British conquest.', $result['prompt']);
        $this->assertSame('Also state the year associated with British conquest.', $result['rubric'][0]['prompt_reference']);
    }

    #[Test]
    public function it_makes_a_source_supported_quantity_explicit_in_the_question(): void
    {
        $data = [
            'prompt' => 'Explain the cultural diversity of Nigeria.',
            'knowledge_basis' => 'source_supported',
            'solution_steps' => [
                ['title' => 'Describe diversity', 'content' => 'Nigeria has 250 to 400 ethnic groups.'],
                ['title' => 'Connect traditions', 'content' => 'These groups have varied cultures and political traditions.'],
            ],
            'criteria' => [
                ['id' => 'C1', 'description' => 'States that Nigeria has 250 to 400 ethnic groups.', 'prompt_reference' => 'cultural diversity of Nigeria', 'points' => 2, 'source_chunk_ids' => [8]],
                ['id' => 'C2', 'description' => 'Explains how cultures and political traditions vary.', 'prompt_reference' => 'Explain the cultural diversity', 'points' => 3, 'source_chunk_ids' => [8]],
            ],
        ];

        $result = (new OpenEndedQuestionResponseValidator)->validate($data, [8], [
            ['id' => 8, 'content' => 'Nigeria encompasses 250 to 400 ethnic groups of widely varied cultures and modes of political organization.'],
        ]);

        $this->assertStringContainsString('Also state the number associated with cultural diversity of Nigeria.', $result['prompt']);
        $this->assertSame('Also state the number associated with cultural diversity of Nigeria.', $result['rubric'][0]['prompt_reference']);
    }
}
