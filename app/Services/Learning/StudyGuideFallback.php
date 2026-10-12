<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;
use App\Models\MaterialChunk;
use App\Support\LearnerText;
use Illuminate\Support\Collection;

final class StudyGuideFallback
{
    /**
     * Keep a learner moving when an AI answer fails citation validation.
     * The concept summary is already linked to the same learner-owned source
     * chunks, so this fallback can cite those exact chunks without inventing
     * new facts or presenting an unverified AI answer.
     *
     * @param  Collection<int, MaterialChunk>  $chunks
     * @return array{content:string,citations:array<int,array{chunk_id:int,excerpt:string}>}|null
     */
    public function forCitationFailure(
        LearningSpace $space,
        string $question,
        Collection $chunks,
        ?int $requestedConceptId = null,
        ?string $language = 'en',
    ): ?array {
        if ($chunks->isEmpty()) {
            return null;
        }

        $chunkIds = $chunks->pluck('id')->all();
        $sourceFilter = fn ($query) => $query
            ->where('relevance_status', 'related')
            ->whereHas('material', fn ($material) => $material->where('learning_space_id', $space->id)->where('status', 'ready'));
        $conceptQuery = $space->concepts()->with(['sources' => fn ($query) => $sourceFilter($query)
            ->when($requestedConceptId === null, fn ($query) => $query->whereIn('material_chunks.id', $chunkIds))
            ->with('material:id,learning_space_id,title')->orderBy('page')->limit(2)]);

        if ($requestedConceptId !== null) {
            $concept = $conceptQuery->whereKey($requestedConceptId)->first();
        } else {
            $normalize = fn (string $text): string => trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($text)) ?? '');
            $questionText = ' '.$normalize($question).' ';
            $concept = $conceptQuery->whereHas('sources', fn ($query) => $sourceFilter($query)
                ->whereIn('material_chunks.id', $chunkIds))
                ->get()
                ->filter(function ($candidate) use ($normalize, $questionText): bool {
                    $name = $normalize($candidate->name);

                    return $name !== '' && str_contains($questionText, ' '.$name.' ');
                })
                ->sortByDesc(fn ($candidate) => mb_strlen($candidate->name))
                ->first();
        }

        if (! $concept || $concept->sources->isEmpty()) {
            return null;
        }

        $citations = $concept->sources->take(2)->map(fn ($chunk) => [
            'chunk_id' => $chunk->id,
            'excerpt' => mb_substr($chunk->content, 0, 500),
        ])->all();

        $name = LearnerText::withoutEmDash($concept->name);
        $description = LearnerText::withoutEmDash($concept->description);
        $copy = new TutorFallbackCopy;
        $language = $copy->language($language);

        if ($language !== 'en') {
            return [
                'content' => LearnerText::withoutEmDash($copy->text($language, 'citation_fallback', ['concept' => $name])),
                'citations' => $citations,
                'concept_id' => $concept->id,
            ];
        }

        return [
            'content' => "I could not verify a full AI explanation, so I am using the source-backed lesson for {$name}.\n\n"
                ."1. Simple idea: {$description}\n\n"
                .'2. Connect it to the source: the cited passages below are the evidence attached to this idea. Read them and notice which detail best supports the core idea.\n\n'
                ."3. Check your understanding: explain {$name} in your own words and name one cited detail that supports your explanation. Reply here and I will help you check it.",
            'citations' => $citations,
            'concept_id' => $concept->id,
        ];
    }
}
