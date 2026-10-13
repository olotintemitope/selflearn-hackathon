<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class ConceptGraphValidator
{
    public function validate(array $data, array $knownChunkIds): array
    {
        $concepts = $data['concepts'] ?? null;
        $dependencies = $data['dependencies'] ?? null;
        if (! is_array($concepts) || $concepts === [] || ! is_array($dependencies)) {
            throw new ConceptExtractionException('Concept output is empty or incomplete.');
        }

        $ids = [];
        $names = [];
        $cleanConcepts = [];
        foreach ($concepts as $concept) {
            $id = $concept['id'] ?? null;
            $name = LearnerText::withoutEmDash((string) ($concept['name'] ?? ''));
            $description = LearnerText::withoutEmDash((string) ($concept['description'] ?? ''));
            $refs = $concept['source_chunk_ids'] ?? [];
            if (! is_string($id) || $id === '' || isset($ids[$id]) || $name === '' || $description === '' || ! is_array($refs) || $refs === []) {
                throw new ConceptExtractionException('Concept output contains invalid or duplicate entries.');
            }
            $normalName = mb_strtolower($name);
            if (isset($names[$normalName]) || array_diff($refs, $knownChunkIds)) {
                throw new ConceptExtractionException('Concept output contains duplicate names or unknown source references.');
            }
            $ids[$id] = true;
            $names[$normalName] = true;
            $cleanConcepts[] = [...$concept, 'name' => $name, 'description' => $description];
        }

        $graph = array_fill_keys(array_keys($ids), []);
        foreach ($dependencies as $dependency) {
            $conceptId = $dependency['concept_id'] ?? null;
            $prerequisiteId = $dependency['prerequisite_id'] ?? null;
            if (! isset($ids[$conceptId], $ids[$prerequisiteId]) || $conceptId === $prerequisiteId || ! is_bool($dependency['source_supported'] ?? null)) {
                throw new ConceptExtractionException('Concept dependencies contain invalid references.');
            }
            $graph[$conceptId][] = $prerequisiteId;
        }
        $this->assertAcyclic($graph);

        return ['concepts' => $cleanConcepts, 'dependencies' => $dependencies];
    }

    private function assertAcyclic(array $graph): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $graph): void {
            if (isset($visiting[$node])) {
                throw new ConceptExtractionException('Concept dependencies must not contain cycles.');
            }
            if (isset($visited[$node])) {
                return;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] as $next) {
                $visit($next);
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($graph) as $node) {
            $visit($node);
        }
    }
}
