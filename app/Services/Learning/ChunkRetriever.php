<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;
use App\Models\MaterialChunk;
use Illuminate\Support\Collection;

final class ChunkRetriever
{
    /** @return Collection<int, MaterialChunk> */
    public function retrieve(LearningSpace $space, string $question, int $limit = 6): Collection
    {
        $terms = collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($question)))
            ->filter(fn ($term) => mb_strlen($term) >= 3)->unique()->values();
        if ($terms->isEmpty()) {
            return collect();
        }

        return MaterialChunk::query()
            ->whereHas('material', fn ($query) => $query->where('learning_space_id', $space->id)->where('status', 'ready'))
            ->with('material:id,learning_space_id,title')
            ->get()
            ->map(fn ($chunk) => ['chunk' => $chunk, 'score' => $terms->sum(
                fn ($term) => substr_count(mb_strtolower($chunk->content), $term)
            )])
            ->filter(fn ($item) => $item['score'] > 0)
            ->sortBy(fn ($item) => [-$item['score'], $item['chunk']->id])
            ->take(max(1, min($limit, 10)))
            ->pluck('chunk')->values();
    }
}
