<?php

namespace App\Services\Learning;

interface PastPaperWrittenFeedbackGenerator
{
    /** @return array{status: string, feedback: string, steps: array<int, array{title: string, detail: string}>, source_chunk_ids: array<int, int>} */
    public function generate(string $question, string $questionType, string $learnerResponse, array $evidence): array;
}
