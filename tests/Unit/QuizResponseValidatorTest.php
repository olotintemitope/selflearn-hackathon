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

        return [
            'unknown source reference' => [['questions' => [$unknownSource]]],
            'invalid answer key' => [['questions' => [$badKey]]],
            'duplicate option identifier' => [['questions' => [$duplicateOption]]],
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
            'source_chunk_ids' => [17],
        ];
    }
}
