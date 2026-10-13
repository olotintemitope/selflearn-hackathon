<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;

/**
 * A deterministic, no-provider first pass for deciding whether extracted pages
 * belong in a learner's study space. Ambiguous matches are left for review.
 */
final class LocalMaterialRelevanceClassifier implements MaterialRelevanceClassifier
{
    public const VERSION = 'local-topic-anchor-v2';

    /**
     * Words that describe a general-purpose study space rather than its topic.
     * These are intentionally not school-subject labels. A broad goal must not
     * make two unrelated uploads look related merely because both are study
     * materials or exams.
     */
    private const BROAD_CONTEXT_TERMS = [
        'any', 'anything', 'batch', 'book', 'books', 'class', 'classes', 'document', 'documents',
        'exam', 'exams', 'evaluation', 'file', 'files', 'general', 'guide', 'guides', 'help', 'my', 'real',
        'learn', 'learning', 'material', 'materials', 'more', 'notes', 'paper', 'papers', 'past',
        'pdf', 'pdfs', 'practice', 'read', 'reading', 'reference', 'references', 'resource',
        'resources', 'review', 'scan', 'scanned', 'scans', 'space', 'study', 'subject', 'subjects',
        'topic', 'topics', 'upload', 'uploaded', 'uploads', 'various', 'wide',
    ];

    private const STOP_WORDS = [
        'about', 'after', 'also', 'and', 'any', 'are', 'because', 'been', 'being', 'between',
        'book', 'chapter', 'course', 'could', 'does', 'each', 'exam', 'explain', 'from', 'have',
        'into', 'learn', 'learning', 'material', 'materials', 'more', 'notes', 'paper', 'pages',
        'please', 'practice', 'references', 'should', 'study', 'that', 'their', 'them', 'there',
        'a', 'an', 'as', 'at', 'be', 'by', 'can', 'did', 'do', 'for', 'had', 'has', 'if', 'in',
        'is', 'it', 'its', 'may', 'me', 'my', 'no', 'not', 'of', 'on', 'or', 'our', 'out', 'so',
        'such', 'than', 'the', 'these', 'this', 'those', 'through', 'to', 'too', 'understand',
        'up', 'using', 'via', 'want', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'will',
        'with', 'would', 'you', 'your',
    ];

    public function hasBroadGoal(LearningSpace $space): bool
    {
        $goalContext = implode(' ', array_filter([
            $space->title,
            $space->goal,
            $space->subject,
        ]));
        $specificGoalTerms = array_diff($this->terms($goalContext), self::BROAD_CONTEXT_TERMS);

        return count($specificGoalTerms) === 0;
    }

    public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
    {
        $broadGoal = $this->hasBroadGoal($space);
        $goalContext = implode(' ', array_filter([
            $space->title,
            $space->goal,
            $space->subject,
        ]));
        $goalTerms = $this->terms($goalContext);
        $topicContext = implode(' ', array_map(
            fn (array $concept) => ($concept['name'] ?? '').($broadGoal ? '' : ' '.($concept['description'] ?? '')),
            $existingConcepts,
        ));
        $topicTerms = $this->terms($topicContext);
        $terms = array_values(array_unique([...$goalTerms, ...$topicTerms]));

        return array_map(function (array $chunk) use ($terms, $topicTerms, $broadGoal, $existingConcepts): array {
            $pageTerms = $this->terms($chunk['content']);
            $matches = array_values(array_intersect($terms, $pageTerms));
            $matchedTopics = [];
            $partialTopics = [];
            if ($broadGoal && $existingConcepts !== []) {
                foreach ($existingConcepts as $concept) {
                    $name = trim((string) ($concept['name'] ?? ''));
                    $nameTerms = $this->terms($name);
                    if ($nameTerms === []) {
                        continue;
                    }

                    $nameMatches = array_values(array_intersect($nameTerms, $pageTerms));
                    $requiredMatches = count($nameTerms) === 1
                        ? 1
                        : max(2, (int) ceil(count($nameTerms) * 0.67));

                    if (count($nameMatches) >= $requiredMatches) {
                        $matchedTopics[] = $name;
                    } elseif ($nameMatches !== []) {
                        $partialTopics[] = $name;
                    }
                }
            }
            $termCount = count($terms);
            $coverage = $termCount === 0 ? 0.0 : count($matches) / $termCount;

            if (trim($chunk['content']) === '') {
                $status = 'possibly_related';
                $confidence = 0.5;
                $reason = 'The study goal or extracted page has too little readable text for a reliable local match.';
            } elseif ($broadGoal && $existingConcepts === [] && $pageTerms !== []) {
                // The first readable material gives a broad study space a
                // starting topic. Later uploads are checked against concepts
                // grounded in this material instead of the generic goal words.
                $status = 'related';
                $confidence = 0.68;
                $reason = 'This first clear material will help set the topic for your study space.';
            } elseif ($broadGoal && $topicTerms === []) {
                $status = 'possibly_related';
                $confidence = 0.5;
                $reason = 'The current study path does not yet have enough topic clues to compare this page reliably.';
            } elseif ($broadGoal && $existingConcepts !== []) {
                if ($matchedTopics !== []) {
                    $status = 'related';
                    $confidence = min(0.94, 0.78 + (count($matchedTopics) / max(1, count($existingConcepts))) * 0.16);
                    $reason = 'This page clearly matches a topic already in your study path: '.implode(', ', array_slice($matchedTopics, 0, 3)).'.';
                } elseif ($partialTopics !== []) {
                    $status = 'possibly_related';
                    $confidence = 0.58;
                    $reason = 'This page shares a few words with your study topics, but the connection is not clear yet. Check it before adding it to your study path.';
                } else {
                    $status = 'separate';
                    $confidence = 0.86;
                    $reason = 'This page covers a different topic from the materials already in this study space, so it was kept separate.';
                }
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
