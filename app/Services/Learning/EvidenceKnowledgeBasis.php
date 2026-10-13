<?php

namespace App\Services\Learning;

/** Applies narrow server-side grounding rules for confirmed clock visuals. */
final class EvidenceKnowledgeBasis
{
    /** @param array<string, mixed> $question @param list<array<string, mixed>> $sourceChunks */
    public function forQuestion(array $question, array $sourceChunks): string
    {
        $generated = [];
        array_walk_recursive($question, function ($value) use (&$generated): void {
            if (is_string($value)) {
                $generated[] = $value;
            }
        });

        return $this->validateClockVisualClaim(
            (string) ($question['knowledge_basis'] ?? 'supplementary'),
            array_values(array_filter(array_column($sourceChunks, 'content'), 'is_string')),
            implode(' ', $generated),
        );
    }

    /**
     * If OCR omitted a clock diagram's hand positions, owner confirmation of
     * displayed times does not turn general hand-position rules into source
     * evidence. Keep those explanations explicitly supplementary.
     *
     * @param  list<string>  $sourceContent
     */
    public function validateClockVisualClaim(string $basis, array $sourceContent, string $generatedContent): string
    {
        $hasConfirmedReadingsOnly = collect($sourceContent)->contains(fn (string $content): bool => str_contains(
            mb_strtolower($content), 'learner-confirmed local clock diagram readings (not mathpix ocr)',
        ));
        $claimsHandPositions = preg_match('/\b(?:hour|minute)\s+hand\b|\bhand positions?\b/iu', $generatedContent) === 1;

        return $hasConfirmedReadingsOnly && $claimsHandPositions ? 'supplementary' : $basis;
    }
}
