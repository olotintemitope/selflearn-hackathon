<?php

namespace Tests\Unit;

use App\Services\Learning\AnswerMarkingException;
use App\Services\Learning\AnswerMarkingValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnswerMarkingValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_a_complete_provisional_mark_with_known_citations(): void
    {
        $rubric = [
            ['id' => 'C1', 'description' => 'Uses the source definition.', 'points' => 2, 'source_chunk_ids' => [11]],
            ['id' => 'C2', 'description' => 'Explains the example.', 'points' => 3, 'source_chunk_ids' => [12]],
        ];
        $result = (new AnswerMarkingValidator)->validate([
            'status' => 'marked', 'points_awarded' => 4, 'feedback' => 'Clear explanation.',
            'criteria_results' => [
                ['criterion_id' => 'C1', 'points_awarded' => 2, 'feedback' => 'Accurate definition.', 'source_chunk_ids' => [11]],
                ['criterion_id' => 'C2', 'points_awarded' => 2, 'feedback' => 'Example partly explained.', 'source_chunk_ids' => [12]],
            ],
        ], $rubric, [11, 12]);

        $this->assertSame(4, $result['points_awarded']);
        $this->assertSame([11], $result['criteria_results'][0]['source_chunk_ids']);
    }

    #[Test]
    public function it_rejects_scores_above_criterion_maxima_and_unknown_or_unrelated_citations(): void
    {
        $rubric = [['id' => 'C1', 'description' => 'Explains the source.', 'points' => 2, 'source_chunk_ids' => [11]]];
        $validator = new AnswerMarkingValidator;
        $overMax = [
            'status' => 'marked', 'points_awarded' => 3, 'feedback' => 'Feedback.',
            'criteria_results' => [['criterion_id' => 'C1', 'points_awarded' => 3, 'feedback' => 'Too many.', 'source_chunk_ids' => [11]]],
        ];
        try {
            $validator->validate($overMax, $rubric, [11]);
            $this->fail('Expected an over-maximum mark to be rejected.');
        } catch (AnswerMarkingException) {
            $this->assertTrue(true);
        }

        $unknownCitation = [
            'status' => 'marked', 'points_awarded' => 1, 'feedback' => 'Feedback.',
            'criteria_results' => [['criterion_id' => 'C1', 'points_awarded' => 1, 'feedback' => 'Claim.', 'source_chunk_ids' => [99]]],
        ];
        $this->expectException(AnswerMarkingException::class);
        $validator->validate($unknownCitation, $rubric, [11]);
    }

    #[Test]
    public function it_returns_no_score_when_review_is_required(): void
    {
        $result = (new AnswerMarkingValidator)->validate([
            'status' => 'needs_review', 'points_awarded' => 0,
            'feedback' => 'The material is insufficient.', 'criteria_results' => [],
        ], [['id' => 'C1', 'description' => 'Criterion.', 'points' => 5, 'source_chunk_ids' => [11]]], [11]);

        $this->assertNull($result['points_awarded']);
        $this->assertSame([], $result['criteria_results']);
    }
}
