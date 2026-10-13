<?php

namespace Tests\Unit;

use App\Services\Learning\MaterialRelevanceException;
use App\Services\Learning\MaterialRelevanceResponseValidator;
use PHPUnit\Framework\TestCase;

class MaterialRelevanceResponseValidatorTest extends TestCase
{
    public function test_accepts_one_valid_classification_per_known_page(): void
    {
        $result = (new MaterialRelevanceResponseValidator)->validate([
            'classifications' => [
                ['chunk_id' => 12, 'status' => 'related', 'confidence' => .91, 'reason' => 'It teaches the target concept.'],
                ['chunk_id' => 13, 'status' => 'possibly_related', 'confidence' => .56, 'reason' => 'Its connection depends on the learner goal.'],
            ],
        ], [12, 13]);

        $this->assertCount(2, $result);
        $this->assertSame('possibly_related', $result[1]['status']);
    }

    public function test_rejects_unknown_duplicate_missing_and_malformed_page_classifications(): void
    {
        $validator = new MaterialRelevanceResponseValidator;
        $invalid = [
            ['classifications' => [['chunk_id' => 99, 'status' => 'related', 'confidence' => .99, 'reason' => 'Unknown page.']]],
            ['classifications' => [
                ['chunk_id' => 12, 'status' => 'related', 'confidence' => .99, 'reason' => 'First.'],
                ['chunk_id' => 12, 'status' => 'separate', 'confidence' => .99, 'reason' => 'Duplicate.'],
            ]],
            ['classifications' => [['chunk_id' => 12, 'status' => 'related', 'confidence' => .99, 'reason' => 'One page omitted.']]],
            ['classifications' => [['chunk_id' => 12, 'status' => 'maybe', 'confidence' => .99, 'reason' => 'Invalid status.']]],
            ['classifications' => [['chunk_id' => 12, 'status' => 'related', 'confidence' => 1.1, 'reason' => 'Invalid confidence.']]],
        ];
        foreach ($invalid as $data) {
            try {
                $validator->validate($data, [12, 13]);
                $this->fail('Invalid classifier output should be rejected.');
            } catch (MaterialRelevanceException) {
                $this->assertTrue(true);
            }
        }
    }
}
