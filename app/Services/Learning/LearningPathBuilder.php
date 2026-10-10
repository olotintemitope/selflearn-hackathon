<?php

namespace App\Services\Learning;

use App\Models\LearningPath;
use App\Models\LearningSpace;
use Illuminate\Support\Facades\DB;

final class LearningPathBuilder
{
    public function build(LearningSpace $space): LearningPath
    {
        $concepts = $space->concepts()->with('prerequisites:id')->orderBy('name')->orderBy('id')->get();
        $remaining = $concepts->mapWithKeys(fn ($concept) => [$concept->id => $concept->prerequisites->pluck('id')->all()])->all();
        $ordered = [];

        while ($remaining !== []) {
            $ready = $concepts->filter(fn ($concept) => array_key_exists($concept->id, $remaining) && $remaining[$concept->id] === [])->first();
            if (! $ready) {
                throw new ConceptExtractionException('Concept dependencies must not contain cycles.');
            }
            $ordered[] = $ready->id;
            unset($remaining[$ready->id]);
            foreach ($remaining as $id => $dependencies) {
                $remaining[$id] = array_values(array_diff($dependencies, [$ready->id]));
            }
        }

        return DB::transaction(function () use ($space, $ordered): LearningPath {
            $path = $space->learningPaths()->latest('version')->first();
            $existing = $path?->items()->orderBy('position')->pluck('concept_id')->all() ?? [];
            if ($path && $existing === $ordered) {
                return $this->loadPath($path);
            }

            $path = $space->learningPaths()->create([
                'goal' => $space->goal,
                'version' => ($path?->version ?? 0) + 1,
            ]);
            foreach ($ordered as $index => $conceptId) {
                $path->items()->create(['concept_id' => $conceptId, 'position' => $index + 1]);
            }

            return $this->loadPath($path);
        });
    }

    private function loadPath(LearningPath $path): LearningPath
    {
        return $path->load(['items.concept' => fn ($query) => $query->with('prerequisites')->withExists('sources')]);
    }
}
