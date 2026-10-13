<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class QuizResponseValidator
{
    /** @param array<int, int> $allowedChunkIds
     *  @return array<int, array{prompt: string, options: array<int, array{id: string, text: string}>, correct_option_id: string, explanation: string, solution_steps: array<int, array{title: string, content: string}>, knowledge_basis: string, source_chunk_ids: array<int, int>}> */
    public function validate(array $data, array $allowedChunkIds, int $expectedCount, string $mode = 'guided', array $evidence = []): array
    {
        if (! isset($data['questions']) || ! is_array($data['questions']) || count($data['questions']) !== $expectedCount) {
            throw new QuizGenerationException('The quiz provider returned an unexpected number of questions.');
        }

        $allowed = array_fill_keys($allowedChunkIds, true);

        return array_values(array_map(function ($question) use ($allowed, $mode, $evidence): array {
            if (! is_array($question)
                || ! is_string($question['prompt'] ?? null) || trim($question['prompt']) === ''
                || ! is_string($question['explanation'] ?? null) || trim($question['explanation']) === ''
                || ! is_array($question['solution_steps'] ?? null) || count($question['solution_steps']) < 2 || count($question['solution_steps']) > 7
                || ! in_array($question['knowledge_basis'] ?? null, ['source_supported', 'supplementary'], true)
                || ! is_array($question['options'] ?? null) || count($question['options']) !== 4
                || ! is_array($question['source_chunk_ids'] ?? null) || $question['source_chunk_ids'] === []) {
                throw new QuizGenerationException('The quiz provider returned an incomplete question.');
            }

            $questionSourceContent = collect($evidence)
                ->whereIn('id', $question['source_chunk_ids'])
                ->pluck('content')->filter(fn ($content): bool => is_string($content))->values()->all();
            $optionTexts = array_map(fn ($option): string => is_array($option) ? (string) ($option['text'] ?? '') : '', $question['options']);
            $stepTexts = array_map(fn ($step): string => is_array($step)
                ? trim((string) ($step['title'] ?? '').' '.(string) ($step['content'] ?? '')) : '', $question['solution_steps']);
            $generatedContent = implode(' ', [
                $question['prompt'], $question['explanation'],
                ...$optionTexts,
                ...$stepTexts,
            ]);
            $knowledgeBasis = (new EvidenceKnowledgeBasis)->validateClockVisualClaim(
                $question['knowledge_basis'], $questionSourceContent, $generatedContent,
            );
            if ($mode === 'exam' && $knowledgeBasis !== 'source_supported') {
                throw new QuizGenerationException('This material does not support a fair source-based exam question yet. Review or add clearer material, or use guided practice.');
            }

            $optionIds = [];
            $options = [];
            foreach ($question['options'] as $option) {
                if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['text'] ?? null) || trim($option['text']) === '') {
                    throw new QuizGenerationException('The quiz provider returned an invalid option.');
                }
                $optionIds[] = $option['id'];
                $options[] = ['id' => $option['id'], 'text' => LearnerText::withoutEmDash($option['text'])];
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

            $solutionSteps = [];
            foreach ($question['solution_steps'] as $step) {
                if (! is_array($step)
                    || ! is_string($step['title'] ?? null) || trim($step['title']) === ''
                    || ! is_string($step['content'] ?? null) || trim($step['content']) === '') {
                    throw new QuizGenerationException('The quiz provider returned an invalid worked solution.');
                }
                $solutionSteps[] = [
                    'title' => LearnerText::withoutEmDash($step['title']),
                    'content' => LearnerText::withoutEmDash($step['content']),
                ];
            }

            return [
                'prompt' => LearnerText::withoutEmDash($question['prompt']),
                'options' => $options,
                'correct_option_id' => $question['correct_option_id'],
                'explanation' => LearnerText::withoutEmDash($question['explanation']),
                'solution_steps' => $solutionSteps,
                'knowledge_basis' => $knowledgeBasis,
                'source_chunk_ids' => array_values(array_unique($sourceIds)),
            ];
        }, $data['questions']));
    }
}
