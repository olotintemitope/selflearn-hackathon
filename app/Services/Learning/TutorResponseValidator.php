<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class TutorResponseValidator
{
    public function validate(array $data, array $chunks): array
    {
        $answer = LearnerText::withoutEmDash((string) ($data['answer'] ?? ''));
        $citations = $data['citations'] ?? null;
        $supplementary = $data['supplementary_sections'] ?? null;
        $insufficient = $data['insufficient_evidence'] ?? null;
        $understandingCheck = $data['understanding_check'] ?? 'not_applicable';
        if ($answer === '' || ! is_array($citations) || ! is_array($supplementary) || ! is_bool($insufficient)
            || ! in_array($understandingCheck, ['not_applicable', 'understood', 'developing', 'needs_support'], true)) {
            throw new TutorException('The tutor returned malformed output.');
        }

        $known = collect($chunks)->keyBy('id');
        $validatedCitations = [];
        foreach ($citations as $citation) {
            if (! is_array($citation) || ! is_int($citation['chunk_id'] ?? null)) {
                throw new TutorException('The tutor returned an unresolved citation.');
            }
            $chunk = $known->get($citation['chunk_id'] ?? null);
            if (! $chunk) {
                throw new TutorException('The tutor returned an unresolved citation.');
            }
            $source = trim((string) ($chunk['content'] ?? ''));
            if ($source === '') {
                throw new TutorException('The tutor returned an unresolved citation.');
            }
            $excerpt = $this->resolveExactExcerpt($source, (string) ($citation['excerpt'] ?? ''))
                ?? mb_substr($source, 0, 500);
            $citation['excerpt'] = $excerpt;
            $validatedCitations[] = $citation;
        }
        foreach ($supplementary as $section) {
            if (! is_array($section)
                || LearnerText::withoutEmDash((string) ($section['title'] ?? '')) === ''
                || LearnerText::withoutEmDash((string) ($section['content'] ?? '')) === '') {
                throw new TutorException('The tutor returned malformed supplementary content.');
            }
        }
        if (! $insufficient && $citations === []) {
            throw new TutorException('A grounded tutor answer must include a citation.');
        }

        $supplementary = array_map(fn (array $section): array => [
            'title' => LearnerText::withoutEmDash($section['title']),
            'content' => LearnerText::withoutEmDash($section['content']),
        ], $supplementary);

        $citations = $validatedCitations ?? [];

        return compact('answer', 'citations', 'supplementary', 'insufficient', 'understandingCheck');
    }

    private function resolveExactExcerpt(string $source, string $quote): ?string
    {
        $quote = trim($quote);
        if ($source === '' || $quote === '') {
            return null;
        }
        if (str_contains($source, $quote)) {
            return $quote;
        }

        $tokens = static function (string $text): array {
            preg_match_all('/[\\pL\\pN]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
            $tokens = [];
            $index = 0;

            while ($index < count($matches[0])) {
                [$word, $start] = $matches[0][$index];
                $end = $start + strlen($word);
                $value = mb_strtolower($word);

                while (isset($matches[0][$index + 1])) {
                    [$nextWord, $nextStart] = $matches[0][$index + 1];
                    $gap = substr($text, $end, $nextStart - $end);
                    if (! preg_match('/^[-\\x{2010}\\x{2011}]\\h*\\R\\h*$/u', $gap)) {
                        break;
                    }
                    $value .= mb_strtolower($nextWord);
                    $end = $nextStart + strlen($nextWord);
                    $index++;
                }

                $tokens[] = ['value' => $value, 'start' => $start, 'end' => $end];
                $index++;
            }

            return $tokens;
        };

        $sourceTokens = $tokens($source);
        $quoteTokens = array_column($tokens($quote), 'value');
        if (count($quoteTokens) < 2 || count($quoteTokens) > count($sourceTokens)) {
            return null;
        }

        for ($start = 0; $start <= count($sourceTokens) - count($quoteTokens); $start++) {
            $candidate = array_slice(array_column($sourceTokens, 'value'), $start, count($quoteTokens));
            if ($candidate === $quoteTokens) {
                $from = $sourceTokens[$start]['start'];
                $to = $sourceTokens[$start + count($quoteTokens) - 1]['end'];

                return substr($source, $from, $to - $from);
            }
        }

        return null;
    }
}
