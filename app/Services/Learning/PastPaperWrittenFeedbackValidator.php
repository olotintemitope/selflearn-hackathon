<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class PastPaperWrittenFeedbackValidator
{
    /** @return array{status: string, feedback: string, steps: array<int, array{title: string, detail: string}>, source_chunk_ids: array<int, int>} */
    public function validate(array $data, array $allowedChunkIds): array
    {
        if (! in_array($data['status'] ?? null, ['ready', 'needs_review'], true)
            || ! is_string($data['feedback'] ?? null)
            || trim($data['feedback']) === ''
            || mb_strlen($data['feedback']) > 1200
            || ! is_array($data['steps'] ?? null)
            || ! is_array($data['source_chunk_ids'] ?? null)) {
            throw new PastPaperWrittenFeedbackException('Written feedback was incomplete.');
        }

        if ($data['status'] === 'needs_review') {
            return [
                'status' => 'needs_review',
                'feedback' => LearnerText::withoutEmDash(trim($data['feedback'])),
                'steps' => [],
                'source_chunk_ids' => [],
            ];
        }

        if (count($data['steps']) < 1 || count($data['steps']) > 6 || $data['source_chunk_ids'] === []) {
            throw new PastPaperWrittenFeedbackException('Written feedback did not include a source-linked explanation.');
        }

        $allowed = array_fill_keys(array_map('intval', $allowedChunkIds), true);
        $sourceIds = [];
        foreach ($data['source_chunk_ids'] as $sourceId) {
            if (! is_int($sourceId) || ! isset($allowed[$sourceId])) {
                throw new PastPaperWrittenFeedbackException('Written feedback cited a source outside this question.');
            }
            $sourceIds[] = $sourceId;
        }

        $steps = [];
        foreach ($data['steps'] as $step) {
            if (! is_array($step)
                || ! is_string($step['title'] ?? null) || trim($step['title']) === '' || mb_strlen($step['title']) > 100
                || ! is_string($step['detail'] ?? null) || trim($step['detail']) === '' || mb_strlen($step['detail']) > 500) {
                throw new PastPaperWrittenFeedbackException('Written feedback contained an invalid explanation step.');
            }
            $steps[] = [
                'title' => LearnerText::withoutEmDash(trim($step['title'])),
                'detail' => LearnerText::withoutEmDash(trim($step['detail'])),
            ];
        }

        return [
            'status' => 'ready',
            'feedback' => LearnerText::withoutEmDash(trim($data['feedback'])),
            'steps' => $steps,
            'source_chunk_ids' => array_values(array_unique($sourceIds)),
        ];
    }
}
