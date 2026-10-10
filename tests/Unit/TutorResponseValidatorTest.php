<?php

namespace Tests\Unit;

use App\Services\Learning\TutorException;
use App\Services\Learning\TutorResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TutorResponseValidatorTest extends TestCase
{
    #[DataProvider('invalidResponses')]
    public function test_it_rejects_unresolvable_or_missing_citations(array $response): void
    {
        $this->expectException(TutorException::class);
        (new TutorResponseValidator)->validate($response, [['id' => 10, 'content' => 'Known evidence.']]);
    }

    public static function invalidResponses(): array
    {
        return [
            'unknown chunk' => [[
                'answer' => 'Answer', 'citations' => [['chunk_id' => 99, 'excerpt' => 'Known evidence']],
                'supplementary_sections' => [], 'insufficient_evidence' => false,
            ]],
            'invented excerpt' => [[
                'answer' => 'Answer', 'citations' => [['chunk_id' => 10, 'excerpt' => 'Invented text']],
                'supplementary_sections' => [], 'insufficient_evidence' => false,
            ]],
            'uncited grounded answer' => [[
                'answer' => 'Answer', 'citations' => [], 'supplementary_sections' => [], 'insufficient_evidence' => false,
            ]],
            'malformed supplementary section' => [[
                'answer' => 'Answer', 'citations' => [],
                'supplementary_sections' => [['title' => '', 'content' => 'Unlabelled outside knowledge']],
                'insufficient_evidence' => true,
            ]],
        ];
    }
}
