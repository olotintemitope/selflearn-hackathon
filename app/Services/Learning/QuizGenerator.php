<?php

namespace App\Services\Learning;

interface QuizGenerator
{
    /** @param array<int, array{id: int, content: string, page: int|null, material_title: string}> $evidence
     *  @return array<int, array{prompt: string, options: array<int, array{id: string, text: string}>, correct_option_id: string, explanation: string, source_chunk_ids: array<int, int>}> */
    public function generate(string $conceptName, array $evidence, int $count = 3): array;
}
