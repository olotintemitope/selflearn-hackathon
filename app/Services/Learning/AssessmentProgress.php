<?php

namespace App\Services\Learning;

use App\Models\AssessmentAnswer;
use App\Models\LearningSpace;
use App\Models\User;

final class AssessmentProgress
{
    public function forSpace(LearningSpace $space, User $user): array
    {
        return AssessmentAnswer::query()
            ->whereHas('attempt', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas('question', fn ($query) => $query->whereHas('assessment', fn ($assessment) => $assessment->where('learning_space_id', $space->id)))
            ->with('question:id,concept_id')
            ->get()
            ->groupBy(fn (AssessmentAnswer $answer) => $answer->question->concept_id)
            ->map(fn ($answers) => [
                'answered' => $answers->count(),
                'correct' => $answers->where('correct', true)->count(),
                'percentage' => (int) round(100 * $answers->where('correct', true)->count() / $answers->count()),
            ])
            ->all();
    }

    public function weakestPrerequisite(LearningSpace $space, int $conceptId, User $user): ?array
    {
        $concept = $space->concepts()->with('prerequisites')->findOrFail($conceptId);
        $prerequisites = $concept->prerequisites;
        if ($prerequisites->isEmpty()) {
            return null;
        }

        $progress = $this->forSpace($space, $user);
        $candidates = $prerequisites->map(fn ($item) => [
            'concept' => $item,
            'progress' => $progress[$item->id] ?? null,
        ]);
        $assessed = $candidates->filter(fn ($item) => $item['progress'] !== null);
        $candidate = ($assessed->isNotEmpty() ? $assessed : $candidates)
            ->sortBy(fn ($item) => $item['progress']['percentage'] ?? -1)
            ->first();

        return ['concept' => $candidate['concept'], 'progress' => $candidate['progress']];
    }
}
