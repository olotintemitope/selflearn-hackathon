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

    public function test_it_resolves_whitespace_and_ocr_line_wraps_to_an_exact_source_excerpt(): void
    {
        $source = "The politi-\ncal state was formed through British conquest in 1903.";
        $result = (new TutorResponseValidator)->validate([
            'answer' => 'The lesson has three steps.',
            'citations' => [['chunk_id' => 10, 'excerpt' => 'POLITICAL  state was formed through British conquest in 1903']],
            'supplementary_sections' => [],
            'insufficient_evidence' => false,
        ], [['id' => 10, 'content' => $source]]);

        $this->assertSame("politi-\ncal state was formed through British conquest in 1903", $result['citations'][0]['excerpt']);
    }

    public function test_it_uses_verbatim_source_text_when_the_model_paraphrases_its_quote(): void
    {
        $result = (new TutorResponseValidator)->validate([
            'answer' => 'The lesson has three steps.',
            'citations' => [['chunk_id' => 10, 'excerpt' => 'The empire brought Nigeria under its rule.']],
            'supplementary_sections' => [],
            'insufficient_evidence' => false,
        ], [['id' => 10, 'content' => 'British conquest established colonial rule in 1903.']]);

        $this->assertSame('British conquest established colonial rule in 1903.', $result['citations'][0]['excerpt']);
    }

    public static function invalidResponses(): array
    {
        return [
            'unknown chunk' => [[
                'answer' => 'Answer', 'citations' => [['chunk_id' => 99, 'excerpt' => 'Known evidence']],
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
