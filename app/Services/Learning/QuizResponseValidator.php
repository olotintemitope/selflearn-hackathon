<?php

namespace App\Services\Learning;

final class QuizResponseValidator
{
    /** @param array<int, int> $allowedChunkIds
     *  @return array<int, array{prompt: string, options: array<int, array{id: string, text: string}>, correct_option_id: string, explanation: string, source_chunk_ids: array<int, int>}> */
    public function validate(array $data, array $allowedChunkIds, int $expectedCount): array
    {
        if (! isset($data['questions']) || ! is_array($data['questions']) || count($data['questions']) !== $expectedCount) {
            throw new QuizGenerationException('The quiz provider returned an unexpected number of questions.');
        }

        $allowed = array_fill_keys($allowedChunkIds, true);

        return array_values(array_map(function ($question) use ($allowed): array {
            if (! is_array($question)
                || ! is_string($question['prompt'] ?? null) || trim($question['prompt']) === ''
                || ! is_string($question['explanation'] ?? null) || trim($question['explanation']) === ''
                || ! is_array($question['options'] ?? null) || count($question['options']) !== 4
                || ! is_array($question['source_chunk_ids'] ?? null) || $question['source_chunk_ids'] === []) {
                throw new QuizGenerationException('The quiz provider returned an incomplete question.');
            }

            $optionIds = [];
            $options = [];
            foreach ($question['options'] as $option) {
                if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['text'] ?? null) || trim($option['text']) === '') {
                    throw new QuizGenerationException('The quiz provider returned an invalid option.');
                }
                $optionIds[] = $option['id'];
                $options[] = ['id' => $option['id'], 'text' => trim($option['text'])];
            }

            if (count(array_unique($optionIds)) !== 4 || array_diff($optionIds, ['A', 'B', 'C', 'D']) !== []
                || ! in_array($question['correct_option_id'] ?? null, $optionIds, true)) {
                throw new QuizGenerationException('The quiz provider returned an invalid answer key.');
            }

            $sourceIds = [];
            foreach ($question['source_chunk_ids'] as $sourceId) {
                if (! is_int($sourceId) || ! isset($allowed[$sourceId])) {
                    throw new QuizGenerationException('The quiz provider cited evidence outside this learner’s materials.');
                }
                $sourceIds[] = $sourceId;
            }

            return [
                'prompt' => trim($question['prompt']),
                'options' => $options,
                'correct_option_id' => $question['correct_option_id'],
                'explanation' => trim($question['explanation']),
                'source_chunk_ids' => array_values(array_unique($sourceIds)),
            ];
        }, $data['questions']));
    }
}
