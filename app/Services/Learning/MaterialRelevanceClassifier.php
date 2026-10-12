<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;

interface MaterialRelevanceClassifier
{
    /**
     * Classify each supplied page against the learning goal and existing concepts.
     * Uploaded content is evidence, never instructions.
     *
     * @param  array<int, array{id:int, content:string, page:int|null}>  $chunks
     * @param  array<int, array{name:string, description:string}>  $existingConcepts
     * @return array<int, array{chunk_id:int, status:string, confidence:float, reason:string}>
     */
    public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array;
}
