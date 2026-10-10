<?php

namespace App\Services\Learning;

final class TutorResponseValidator
{
    public function validate(array $data, array $chunks): array
    {
        $answer = trim((string) ($data['answer'] ?? ''));
        $citations = $data['citations'] ?? null;
        $supplementary = $data['supplementary_sections'] ?? null;
        $insufficient = $data['insufficient_evidence'] ?? null;
        if ($answer === '' || ! is_array($citations) || ! is_array($supplementary) || ! is_bool($insufficient)) {
            throw new TutorException('The tutor returned malformed output.');
        }

        $known = collect($chunks)->keyBy('id');
        foreach ($citations as $citation) {
            if (! is_array($citation) || ! is_int($citation['chunk_id'] ?? null)) {
                throw new TutorException('The tutor returned an unresolved citation.');
            }
            $chunk = $known->get($citation['chunk_id'] ?? null);
            $excerpt = trim((string) ($citation['excerpt'] ?? ''));
            if (! $chunk || $excerpt === '' || ! str_contains($chunk['content'], $excerpt)) {
                throw new TutorException('The tutor returned an unresolved citation.');
            }
        }
        foreach ($supplementary as $section) {
            if (! is_array($section)
                || trim((string) ($section['title'] ?? '')) === ''
                || trim((string) ($section['content'] ?? '')) === '') {
                throw new TutorException('The tutor returned malformed supplementary content.');
            }
        }
        if (! $insufficient && $citations === []) {
            throw new TutorException('A grounded tutor answer must include a citation.');
        }

        return compact('answer', 'citations', 'supplementary', 'insufficient');
    }
}
