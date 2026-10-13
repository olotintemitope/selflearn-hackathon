<?php

namespace Tests\Unit;

use App\Services\Learning\QuizGenerationException;
use App\Services\Learning\QuizResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuizResponseValidatorTest extends TestCase
{
    public function test_it_validates_exact_count_unique_options_answer_key_and_allowed_evidence(): void
    {
        $result = (new QuizResponseValidator)->validate(['questions' => [self::question()]], [17], 1);

        $this->assertSame('A', $result[0]['correct_option_id']);
        $this->assertSame([17], $result[0]['source_chunk_ids']);
        $this->assertSame('source_supported', $result[0]['knowledge_basis']);
        $this->assertCount(2, $result[0]['solution_steps']);
        $question = self::question();
        $emDash = "\u{2014}";
        $question['prompt'] = 'Why does energy change '.$emDash.' but not disappear?';
        $question['options'][0]['text'] = 'It changes '.$emDash.' it is not destroyed.';
        $cleaned = (new QuizResponseValidator)->validate(['questions' => [$question]], [17], 1);
        $this->assertSame('Why does energy change: but not disappear?', $cleaned[0]['prompt']);
        $this->assertSame('It changes: it is not destroyed.', $cleaned[0]['options'][0]['text']);
    }

    public function test_it_accepts_and_preserves_an_explicitly_supplementary_topic_question(): void
    {
        $question = self::question();
        $question['knowledge_basis'] = 'supplementary';

        $result = (new QuizResponseValidator)->validate(['questions' => [$question]], [17], 1);

        $this->assertSame('supplementary', $result[0]['knowledge_basis']);
    }

    public function test_exam_mode_rejects_questions_that_need_supplementary_knowledge(): void
    {
        $question = self::question();
        $question['knowledge_basis'] = 'supplementary';

        $this->expectException(QuizGenerationException::class);
        (new QuizResponseValidator)->validate(['questions' => [$question]], [17], 1, 'exam');
    }

    public function test_clock_hand_questions_are_supplementary_when_the_source_only_has_confirmed_times(): void
    {
        $question = self::question();
        $question['prompt'] = 'The minute hand points to 6. What time is shown?';
        $clockEvidence = [[
            'id' => 17,
            'content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 2 shows 05:30.',
        ]];

        $result = (new QuizResponseValidator)->validate(['questions' => [$question]], [17], 1, 'guided', $clockEvidence);

        $this->assertSame('supplementary', $result[0]['knowledge_basis']);
    }

    public function test_exam_mode_rejects_hand_position_questions_from_a_clock_source_with_only_confirmed_times(): void
    {
        $question = self::question();
        $question['prompt'] = 'The minute hand points to 6. What time is shown?';

        $this->expectException(QuizGenerationException::class);
        (new QuizResponseValidator)->validate(['questions' => [$question]], [17], 1, 'exam', [[
            'id' => 17,
            'content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 2 shows 05:30.',
        ]]);
    }

    #[DataProvider('invalidResponses')]
    public function test_it_rejects_untrusted_or_malformed_question_content(array $response): void
    {
        $this->expectException(QuizGenerationException::class);
        (new QuizResponseValidator)->validate($response, [17], 1);
    }

    public static function invalidResponses(): array
    {
        $valid = self::question();
        $unknownSource = $valid;
        $unknownSource['source_chunk_ids'] = [99];
        $badKey = $valid;
        $badKey['correct_option_id'] = 'X';
        $duplicateOption = $valid;
        $duplicateOption['options'][1]['id'] = 'A';
        $missingBasis = $valid;
        unset($missingBasis['knowledge_basis']);
        $invalidBasis = $valid;
        $invalidBasis['knowledge_basis'] = 'unverified';
        $missingSolution = $valid;
        unset($missingSolution['solution_steps']);
        $emptySolutionStep = $valid;
        $emptySolutionStep['solution_steps'][0]['content'] = ' ';

        return [
            'unknown source reference' => [['questions' => [$unknownSource]]],
            'invalid answer key' => [['questions' => [$badKey]]],
            'duplicate option identifier' => [['questions' => [$duplicateOption]]],
            'missing knowledge basis' => [['questions' => [$missingBasis]]],
            'invalid knowledge basis' => [['questions' => [$invalidBasis]]],
            'missing worked solution' => [['questions' => [$missingSolution]]],
            'empty solution step' => [['questions' => [$emptySolutionStep]]],
            'wrong question count' => [['questions' => []]],
        ];
    }

    private static function question(): array
    {
        return [
            'prompt' => 'Question?',
            'options' => [
                ['id' => 'A', 'text' => 'A'], ['id' => 'B', 'text' => 'B'],
                ['id' => 'C', 'text' => 'C'], ['id' => 'D', 'text' => 'D'],
            ],
            'correct_option_id' => 'A',
            'explanation' => 'Evidence supports A.',
            'solution_steps' => [
                ['title' => 'Use the evidence', 'content' => 'The source supports this answer.'],
                ['title' => 'Select the answer', 'content' => 'Choose A.'],
            ],
            'knowledge_basis' => 'source_supported',
            'source_chunk_ids' => [17],
        ];
    }
}
