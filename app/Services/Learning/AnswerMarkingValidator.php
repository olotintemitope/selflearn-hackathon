<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class AnswerMarkingValidator
{
    /** @param array<int, array{id: string, description: string, points: int, source_chunk_ids: array<int, int>}> $rubric
     * @param  array<int, int>  $allowedChunkIds
     *  @return array{status: string, points_awarded: int|null, feedback: string, criteria_results: array<int, array{criterion_id: string, points_awarded: int, feedback: string, source_chunk_ids: array<int, int}>} */
    public function validate(array $data, array $rubric, array $allowedChunkIds): array
    {
        if (! in_array($data['status'] ?? null, ['marked', 'needs_review'], true)
            || ! is_int($data['points_awarded'] ?? null)
            || ! is_string($data['feedback'] ?? null) || trim($data['feedback']) === ''
            || ! is_array($data['criteria_results'] ?? null)) {
            throw new AnswerMarkingException('AI marking returned an incomplete result.');
        }

        if ($data['status'] === 'needs_review') {
            if ($data['points_awarded'] !== 0 || $data['criteria_results'] !== []) {
                throw new AnswerMarkingException('AI marking returned points for an answer that needs human review.');
            }

            return ['status' => 'needs_review', 'points_awarded' => null, 'feedback' => LearnerText::withoutEmDash($data['feedback']), 'criteria_results' => []];
        }

        $rubricById = collect($rubric)->keyBy('id');
        $allowed = array_fill_keys($allowedChunkIds, true);
        $results = [];
        $seen = [];
        $total = 0;
        foreach ($data['criteria_results'] as $result) {
            if (! is_array($result)
                || ! is_string($result['criterion_id'] ?? null)
                || ! $rubricById->has($result['criterion_id'])
                || in_array($result['criterion_id'], $seen, true)
                || ! is_int($result['points_awarded'] ?? null)
                || $result['points_awarded'] < 0
                || $result['points_awarded'] > $rubricById->get($result['criterion_id'])['points']
                || ! is_string($result['feedback'] ?? null) || trim($result['feedback']) === ''
                || ! is_array($result['source_chunk_ids'] ?? null)) {
                throw new AnswerMarkingException('AI marking returned an invalid criterion result.');
            }

            $sourceIds = [];
            foreach ($result['source_chunk_ids'] as $sourceId) {
                if (! is_int($sourceId) || ! isset($allowed[$sourceId])
                    || ! in_array($sourceId, $rubricById->get($result['criterion_id'])['source_chunk_ids'], true)) {
                    throw new AnswerMarkingException('AI marking cited evidence outside the authorized question sources.');
                }
                $sourceIds[] = $sourceId;
            }

            if ($result['points_awarded'] > 0 && $sourceIds === []) {
                throw new AnswerMarkingException('AI marking awarded points without a supporting source citation.');
            }

            $seen[] = $result['criterion_id'];
            $total += $result['points_awarded'];
            $results[] = [
                'criterion_id' => $result['criterion_id'],
                'points_awarded' => $result['points_awarded'],
                'feedback' => LearnerText::withoutEmDash($result['feedback']),
                'source_chunk_ids' => array_values(array_unique($sourceIds)),
            ];
        }

        $expectedTotal = collect($rubric)->sum('points');
        if (count($results) !== count($rubric) || $total !== $data['points_awarded'] || $total > $expectedTotal) {
            throw new AnswerMarkingException('AI marking returned scores that do not match the rubric.');
        }

        return ['status' => 'marked', 'points_awarded' => $total, 'feedback' => LearnerText::withoutEmDash($data['feedback']), 'criteria_results' => $results];
    }
}
