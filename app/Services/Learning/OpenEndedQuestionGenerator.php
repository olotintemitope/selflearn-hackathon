<?php

namespace App\Services\Learning;

interface OpenEndedQuestionGenerator
{
    /** @param array<int, array{id: int, content: string, page: int|null, material_title: string}> $evidence
     *  @return array{prompt: string, knowledge_basis: string, solution_steps: array<int, array{title: string, content: string}>, rubric: array<int, array{id: string, description: string, prompt_reference: string, points: int, source_chunk_ids: array<int, int>}>, max_points: int, source_chunk_ids: array<int, int>} */
    public function generate(string $conceptName, array $evidence, string $mode = 'guided'): array;
}
