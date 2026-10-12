<?php

namespace App\Services\Learning;

interface AnswerMarker
{
    /** @param array<int, array{id: string, description: string, points: int, source_chunk_ids: array<int, int>}> $rubric
     *  @param array<int, array{id: int, content: string, page: int|null, material_title: string}> $evidence
     *  @return array{status: string, points_awarded: int|null, feedback: string, criteria_results: array<int, array{criterion_id: string, points_awarded: int, feedback: string, source_chunk_ids: array<int, int>}>} */
    public function mark(string $question, string $knowledgeBasis, array $rubric, string $learnerResponse, array $evidence): array;
}
