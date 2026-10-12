<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class OpenEndedQuestionResponseValidator
{
    /** @param array<int, int> $allowedChunkIds
     *  @return array{prompt: string, knowledge_basis: string, solution_steps: array<int, array{title: string, content: string}>, rubric: array<int, array{id: string, description: string, prompt_reference: string, points: int, source_chunk_ids: array<int, int>}>, max_points: int, source_chunk_ids: array<int, int>} */
    public function validate(array $data, array $allowedChunkIds, array $evidence = [], string $mode = 'guided'): array
    {
        if (! is_string($data['prompt'] ?? null) || trim($data['prompt']) === ''
            || ! in_array($data['knowledge_basis'] ?? null, ['source_supported', 'supplementary'], true)
            || ! is_array($data['criteria'] ?? null) || count($data['criteria']) < 2 || count($data['criteria']) > 5
            || ! is_array($data['solution_steps'] ?? null) || count($data['solution_steps']) < 2 || count($data['solution_steps']) > 7) {
            throw new OpenEndedQuestionGenerationException('The written question did not include a usable marking guide.');
        }

        $citedIds = collect($data['criteria'])->flatMap(fn ($criterion) => is_array($criterion) ? ($criterion['source_chunk_ids'] ?? []) : [])->unique()->all();
        $sourceContent = collect($evidence)->whereIn('id', $citedIds)->pluck('content')
            ->filter(fn ($content): bool => is_string($content))->values()->all();
        $solutionContent = array_map(fn ($step): string => is_array($step)
            ? trim((string) ($step['title'] ?? '').' '.(string) ($step['content'] ?? '')) : '', $data['solution_steps']);
        $criteriaContent = array_map(fn ($criterion): string => is_array($criterion)
            ? trim((string) ($criterion['description'] ?? '').' '.(string) ($criterion['prompt_reference'] ?? '')) : '', $data['criteria']);
        $generatedContent = implode(' ', [
            $data['prompt'],
            ...$solutionContent,
            ...$criteriaContent,
        ]);
        $knowledgeBasis = (new EvidenceKnowledgeBasis)->validateClockVisualClaim(
            $data['knowledge_basis'], $sourceContent, $generatedContent,
        );

        if ($mode === 'exam' && $knowledgeBasis !== 'source_supported') {
            throw new OpenEndedQuestionGenerationException('This material does not support a fair source-based written exam question yet. Review or add clearer material, or use guided practice.');
        }

        $allowed = array_fill_keys($allowedChunkIds, true);
        $criteria = [];
        $ids = [];
        $allSourceIds = [];
        $points = 0;
        $solutionSteps = [];
        $questionPrompt = $data['prompt'];

        foreach ($data['solution_steps'] as $step) {
            if (! is_array($step)
                || ! is_string($step['title'] ?? null) || trim($step['title']) === ''
                || ! is_string($step['content'] ?? null) || trim($step['content']) === '') {
                throw new OpenEndedQuestionGenerationException('The written question included an invalid worked solution.');
            }
            $solutionSteps[] = [
                'title' => LearnerText::withoutEmDash($step['title']),
                'content' => LearnerText::withoutEmDash($step['content']),
            ];
        }

        foreach ($data['criteria'] as $criterion) {
            if (! is_array($criterion)
                || ! is_string($criterion['id'] ?? null) || ! preg_match('/^C[1-5]$/', $criterion['id'])
                || ! is_string($criterion['description'] ?? null) || trim($criterion['description']) === ''
                || ! is_string($criterion['prompt_reference'] ?? null) || trim($criterion['prompt_reference']) === ''
                || ! is_int($criterion['points'] ?? null) || $criterion['points'] < 1 || $criterion['points'] > 5
                || ! is_array($criterion['source_chunk_ids'] ?? null) || $criterion['source_chunk_ids'] === []) {
                throw new OpenEndedQuestionGenerationException('The written question included an invalid marking criterion.');
            }

            $ids[] = $criterion['id'];
            $sourceIds = $this->validatedSources($criterion['source_chunk_ids'], $allowed);
            $allSourceIds = [...$allSourceIds, ...$sourceIds];
            $points += $criterion['points'];
            $promptReference = trim($criterion['prompt_reference']);
            $prompt = $questionPrompt;
            if (! $this->containsPromptRequirement($prompt, $promptReference)) {
                throw new OpenEndedQuestionGenerationException('The written question marking guide includes an unasked requirement.');
            }
            if (! $this->containsAllNumericRequirements($prompt, $criterion['description'], $promptReference)) {
                $missingNumbers = $this->missingNumericRequirements($prompt, $criterion['description']);
                if ($missingNumbers === [] || ! $this->numbersAreSupported($missingNumbers, $sourceIds, $evidence)) {
                    throw new OpenEndedQuestionGenerationException('The written question marking guide includes an unasked requirement.');
                }
                $dateRequirement = collect($missingNumbers)->every(fn (string $number): bool => preg_match('/^(?:1\d{3}|20\d{2})$/', $number) === 1);
                $detail = rtrim($promptReference, " .!?\t\n\r\0\x0B");
                $promptReference = $dateRequirement
                    ? 'Also state the year associated with '.$detail.'.'
                    : 'Also state the number associated with '.$detail.'.';
                $questionPrompt = rtrim($questionPrompt)."\n\n".$promptReference;
            }

            $criteria[] = [
                'id' => $criterion['id'],
                'description' => LearnerText::withoutEmDash($criterion['description']),
                'points' => $criterion['points'],
                'prompt_reference' => LearnerText::withoutEmDash($promptReference),
                'source_chunk_ids' => $sourceIds,
            ];
        }

        if (count(array_unique($ids)) !== count($ids) || $points !== 5) {
            throw new OpenEndedQuestionGenerationException('The written question marking guide did not total five points.');
        }

        return [
            'prompt' => LearnerText::withoutEmDash($questionPrompt),
            'knowledge_basis' => $knowledgeBasis,
            'solution_steps' => $solutionSteps,
            'rubric' => $criteria,
            'max_points' => $points,
            'source_chunk_ids' => array_values(array_unique($allSourceIds)),
        ];
    }

    private function validatedSources(array $sourceIds, array $allowed): array
    {
        $validated = [];
        foreach ($sourceIds as $sourceId) {
            if (! is_int($sourceId) || ! isset($allowed[$sourceId])) {
                throw new OpenEndedQuestionGenerationException('The written question cited evidence outside this learner’s materials.');
            }
            $validated[] = $sourceId;
        }

        return array_values(array_unique($validated));
    }

    private function containsPromptRequirement(string $prompt, string $reference): bool
    {
        $normalize = fn (string $value): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';

        return str_contains($normalize($prompt), $normalize($reference));
    }

    private function containsAllNumericRequirements(string $prompt, string $criterion, string $promptReference): bool
    {
        $missing = $this->missingNumericRequirements($prompt, $criterion);
        if ($missing === []) {
            return true;
        }

        $areDates = collect($missing)->every(fn (string $number): bool => preg_match('/^(?:1\d{3}|20\d{2})$/', $number) === 1);
        $asksForDate = preg_match('/\b(year|date|when)\b/i', $promptReference) === 1;
        $asksForNumber = preg_match('/\b(number|amount|quantity|how many|figure|count)\b/i', $promptReference) === 1;

        return $areDates ? $asksForDate : $asksForNumber;
    }

    private function missingNumericRequirements(string $prompt, string $criterion): array
    {
        preg_match_all('/(?<![\pL\pN])\d{2,}(?![\pL\pN])/u', $criterion, $matches);

        return array_values(array_filter($matches[0], fn (string $number): bool => ! preg_match('/(?<![\pL\pN])'.preg_quote($number, '/').'(?![\pL\pN])/u', $prompt)
        ));
    }

    private function numbersAreSupported(array $numbers, array $sourceIds, array $evidence): bool
    {
        $sources = collect($evidence)->keyBy('id');
        foreach ($numbers as $number) {
            $supported = collect($sourceIds)->contains(function (int $sourceId) use ($sources, $number): bool {
                $content = (string) ($sources->get($sourceId)['content'] ?? '');

                return preg_match('/(?<![\pL\pN])'.preg_quote($number, '/').'(?![\pL\pN])/u', $content) === 1;
            });
            if (! $supported) {
                return false;
            }
        }

        return $sourceIds !== [] && $evidence !== [];
    }
}
