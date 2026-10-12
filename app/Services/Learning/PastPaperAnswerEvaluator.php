<?php

namespace App\Services\Learning;

use App\Models\PastPaperQuestion;

/** Evaluates only answer keys that can be read unambiguously from the source response. */
final class PastPaperAnswerEvaluator
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{outcome:string,marking_basis:string}
     */
    public function evaluate(PastPaperQuestion $question, array $response): array
    {
        $ungraded = ['outcome' => 'submitted_ungraded', 'marking_basis' => 'none'];
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
