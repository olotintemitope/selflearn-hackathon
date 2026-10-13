<?php

namespace App\Services\Learning;

use App\Models\PastPaperQuestion;
use App\Models\PastPaperVerifiedAnswer;

/** Evaluates only answer keys that can be read unambiguously from the source response. */
final class PastPaperAnswerEvaluator
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{outcome:string,marking_basis:string,verified_answer_id?:string}
     */
    public function evaluate(PastPaperQuestion $question, array $response): array
    {
        $ungraded = ['outcome' => 'submitted_ungraded', 'marking_basis' => 'none', 'verified_answer_id' => null];
        $learningSpaceId = $question->material()->value('learning_space_id');
        $verifiedAnswer = PastPaperVerifiedAnswer::query()
            ->where('past_paper_question_id', $question->id)
            ->where('paper_extraction_version', $question->extraction_version)
            ->whereHas('markingSchemeMatch', fn ($query) => $query
                ->where('paper_material_id', $question->material_id)
                ->where('paper_extraction_version', $question->extraction_version)
                ->whereHas('markingScheme', fn ($scheme) => $scheme
                    ->where('learning_space_id', $learningSpaceId)
                    ->where('role', 'marking_scheme')
                    ->where('status', 'ready')
                    ->whereColumn('materials.extraction_version', 'past_paper_marking_scheme_matches.marking_scheme_extraction_version')))
            ->orderByDesc('confirmation_version')
            ->first();

        if ($verifiedAnswer) {
            $key = collect($verifiedAnswer->option_ids ?? [])->map(fn ($id): string => strtoupper((string) $id))->unique()->sort()->values()->all();
            $valid = collect($question->options ?? [])->pluck('id')->map(fn ($id): string => strtoupper((string) $id))->all();

            if ($key !== [] && collect($key)->every(fn (string $id): bool => in_array($id, $valid, true))
                && ($question->question_type === 'multiple_select' || count($key) === 1)) {
                $answer = $question->question_type === 'multiple_select'
                    ? ($response['values'] ?? null)
                    : (isset($response['value']) ? [$response['value']] : null);

                if (is_array($answer) && $answer !== []) {
                    $answer = array_values(array_unique(array_map('strtoupper', $answer)));
                    sort($answer);

                    return [
                        'outcome' => $answer === $key ? 'correct' : 'incorrect',
                        'marking_basis' => 'confirmed_marking_scheme',
                        'verified_answer_id' => $verifiedAnswer->id,
                    ];
                }
            }
        }

        if ($question->answer_source !== 'source_expected_response' || ! is_string($question->expected_answer)) {
            return $ungraded;
        }

        $key = $this->optionLabels($question->expected_answer, $question->options ?? []);
        if ($key === null || ! in_array($question->question_type, ['single_choice', 'true_false', 'multiple_select'], true)) {
            return $ungraded;
        }

        $answer = $question->question_type === 'multiple_select'
            ? ($response['values'] ?? null)
            : (isset($response['value']) ? [$response['value']] : null);
        if (! is_array($answer) || $answer === []) {
            return $ungraded;
        }

        $answer = array_values(array_unique(array_map('strtoupper', $answer)));
        sort($answer);
        sort($key);

        return [
            'outcome' => $answer === $key ? 'correct' : 'incorrect',
            'marking_basis' => 'extracted_source_response',
            'verified_answer_id' => null,
        ];
    }

    /** @param array<int, array{id:string,text:string}> $options
     * @return array<int, string>|null
     */
    private function optionLabels(string $expected, array $options): ?array
    {
        $valid = array_map(static fn (array $option): string => strtoupper($option['id']), $options);
        if ($valid === []) {
            return null;
        }

        $expected = trim($expected);
        if (! preg_match('/^(?:option\s+)?([A-H])(?:\s*(?:,|\band\b|&)\s*(?:option\s+)?([A-H]))*\.?$/iu', $expected)) {
            return null;
        }

        preg_match_all('/\b([A-H])\b/iu', $expected, $matches);
        $labels = array_values(array_unique(array_map('strtoupper', $matches[1])));
        foreach ($labels as $label) {
            if (! in_array($label, $valid, true)) {
                return null;
            }
        }

        if (count($labels) !== count($matches[1])) {
            return null;
        }

        return $labels;
    }
}
