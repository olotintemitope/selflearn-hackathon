<?php

namespace Tests\Unit;

use App\Services\Learning\ConceptExtractionException;
use App\Services\Learning\ConceptGraphValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConceptGraphValidatorTest extends TestCase
{
    public function test_accepts_grounded_acyclic_technical_concepts(): void
    {
        $data = ['concepts' => [
            ['id' => 'html', 'name' => 'HTML', 'description' => 'Document structure', 'source_chunk_ids' => [10]],
            ['id' => 'css', 'name' => 'CSS', 'description' => 'Presentation rules', 'source_chunk_ids' => [11]],
        ], 'dependencies' => [['concept_id' => 'css', 'prerequisite_id' => 'html', 'source_supported' => false]]];
        $this->assertSame($data, (new ConceptGraphValidator)->validate($data, [10, 11]));
    }

    #[DataProvider('invalidGraphs')]
    public function test_rejects_invalid_graphs(array $data): void
    {
        $this->expectException(ConceptExtractionException::class);
        (new ConceptGraphValidator)->validate($data, [10, 11]);
    }

    public static function invalidGraphs(): array
    {
        return [
            'unknown citation' => [[
                'concepts' => [['id' => 'a', 'name' => 'A', 'description' => 'A concept', 'source_chunk_ids' => [999]]],
                'dependencies' => [],
            ]],
            'cycle' => [[
                'concepts' => [
                    ['id' => 'a', 'name' => 'A', 'description' => 'A concept', 'source_chunk_ids' => [10]],
                    ['id' => 'b', 'name' => 'B', 'description' => 'B concept', 'source_chunk_ids' => [11]],
                ],
                'dependencies' => [
                    ['concept_id' => 'a', 'prerequisite_id' => 'b', 'source_supported' => true],
                    ['concept_id' => 'b', 'prerequisite_id' => 'a', 'source_supported' => true],
                ],
            ]],
        ];
    }
}
