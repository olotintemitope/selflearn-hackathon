<?php

namespace App\Services\Learning;

use App\Models\LearningPath;
use App\Models\LearningPathItem;

final class StudyPathGuide
{
    /**
     * Recommend one next concept using the prerequisite-first path and the
     * learner's latest practice signal. The 70% review prompt is a planning
     * heuristic, not a mastery score or a gate.
     *
     * @param  array<int, array{answered:int, correct:int, percentage:int}>  $progress
     * @return array{item:LearningPathItem, reason:string, minutes:int, read_minutes:int, tutor_minutes:int, practice_minutes:int, needs_review:bool}|null
     */
    public function nextStep(LearningPath $path, array $progress, array $tutorCheckProgress = [], array $focusConceptIds = []): ?array
    {
        $items = $path->items;
        if ($items->isEmpty()) {
            return null;
        }

        // Focus on the selected topics and their foundations without removing
        // anything from the learning path or allowing prerequisites to be skipped.
        if ($focusConceptIds !== []) {
            $byId = $items->keyBy('concept_id');
            $inFocus = [];
            $includeWithPrerequisites = function (int $conceptId) use (&$includeWithPrerequisites, &$inFocus, $byId): void {
                if (isset($inFocus[$conceptId]) || ! $byId->has($conceptId)) {
                    return;
                }
                $inFocus[$conceptId] = true;
                foreach ($byId->get($conceptId)->concept->prerequisites as $prerequisite) {
                    $includeWithPrerequisites((int) $prerequisite->id);
                }
            };
            foreach ($focusConceptIds as $conceptId) {
                $includeWithPrerequisites((int) $conceptId);
            }
            $focusedItems = $items->filter(fn ($item) => isset($inFocus[$item->concept_id]));
            if ($focusedItems->isNotEmpty()) {
                $items = $focusedItems;
            }
        }

        $item = null;
        $reason = null;
        $needsReview = false;

        foreach ($items as $candidate) {
            if (($tutorCheckProgress[$candidate->concept_id] ?? null) === 'needs_support') {
                $item = $candidate;
                $reason = 'Your tutor check suggests another explanation would help before you move on.';
                $needsReview = true;
                break;
            }

            $result = $progress[$candidate->concept_id] ?? null;
            if ($result === null) {
                $item = $candidate;
                $reason = 'Start with the next idea in your learning path.';
                break;
            }

            if ($result['percentage'] < 70) {
                $item = $candidate;
                $reason = 'Your practice suggests a review before you move on.';
                $needsReview = true;
                break;
            }
        }

        if (! $item) {
            $item = $items->sortBy(fn ($candidate) => $progress[$candidate->concept_id]['percentage'] ?? 100)->first();
            $reason = 'Revisit this idea to strengthen recall before moving on.';
            $needsReview = true;
        }

        return [
            'item' => $item,
            'reason' => $reason,
            'minutes' => 15,
            'read_minutes' => 3,
            'tutor_minutes' => 5,
            'practice_minutes' => 7,
            'needs_review' => $needsReview,
        ];
    }
}
