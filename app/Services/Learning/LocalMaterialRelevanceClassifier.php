<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;

/**
 * A deterministic, no-provider first pass for deciding whether extracted pages
 * belong in a learner's study space. Ambiguous matches are left for review.
 */
final class LocalMaterialRelevanceClassifier implements MaterialRelevanceClassifier
{
    public const VERSION = 'local-keyword-relevance-v1';

    private const STOP_WORDS = [
        'about', 'after', 'also', 'and', 'any', 'are', 'because', 'been', 'being', 'between',
        'book', 'chapter', 'course', 'could', 'does', 'each', 'exam', 'explain', 'from', 'have',
        'into', 'learn', 'learning', 'material', 'materials', 'more', 'notes', 'paper', 'pages',
        'please', 'practice', 'references', 'should', 'study', 'that', 'their', 'them', 'there',
        'these', 'this', 'those', 'through', 'understand', 'using', 'want', 'what', 'when',
        'where', 'which', 'with', 'would', 'your',
    ];

    public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
    {
        $context = implode(' ', array_filter([
            $space->title,
            $space->goal,
            $space->subject,
            ...array_map(fn (array $concept) => ($concept['name'] ?? '').' '.($concept['description'] ?? ''), $existingConcepts),
        ]));
        $terms = $this->terms($context);

        return array_map(function (array $chunk) use ($terms): array {
            $pageTerms = $this->terms($chunk['content']);
            $matches = array_values(array_intersect($terms, $pageTerms));
            $termCount = count($terms);
            $coverage = $termCount === 0 ? 0.0 : count($matches) / $termCount;

            if (trim($chunk['content']) === '' || $termCount === 0) {
                $status = 'possibly_related';
                $confidence = 0.5;
                $reason = 'The study goal or extracted page has too little readable text for a reliable local match.';
            } elseif (count($matches) >= 2 || ($termCount === 1 && count($matches) === 1)) {
                $status = 'related';
                $confidence = min(0.96, 0.78 + $coverage * 0.18);
                $reason = 'This page shares the study topic terms '.implode(', ', array_slice($matches, 0, 4)).'.';
            } elseif (count($matches) === 1) {
                $status = 'possibly_related';
                $confidence = 0.58;
                $reason = 'This page has a limited topic-word match, so its relevance needs review.';
            } else {
                $status = 'separate';
                $confidence = 0.82;
                $reason = 'No meaningful study-topic terms were found in this page.';
            }

            return [
                'chunk_id' => $chunk['id'],
                'status' => $status,
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }, $chunks);
    }

    /** @return array<int, string> */
    private function terms(?string $text): array
    {
        preg_match_all('/[\pL\pN]{2,}/u', mb_strtolower($text ?? ''), $matches);

        $terms = [];
        foreach ($matches[0] as $word) {
            if (in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $terms[] = $this->normalize($word);
        }

        return array_values(array_unique(array_filter($terms)));
    }

    private function normalize(string $word): string
    {
        if (in_array($word, ['nigerian', 'nigerians'], true)) {
            return 'nigeria';
        }
        if (in_array($word, ['historical', 'historically', 'historic', 'histories'], true)) {
            return 'history';
        }
        if (in_array($word, ['clocks'], true)) {
            return 'clock';
        }
        if (in_array($word, ['chemical', 'chemicals'], true)) {
            return 'chemistry';
        }

        if (str_ends_with($word, 'ies') && mb_strlen($word) > 4) {
            return mb_substr($word, 0, -3).'y';
        }
        if (str_ends_with($word, 'ing') && mb_strlen($word) > 5) {
            return mb_substr($word, 0, -3);
        }
        if (str_ends_with($word, 'ed') && mb_strlen($word) > 4) {
            return mb_substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && mb_strlen($word) > 3) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }
}
