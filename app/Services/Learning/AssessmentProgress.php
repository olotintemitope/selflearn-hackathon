<?php

namespace App\Services\Learning;

use App\Models\AssessmentAnswer;
use App\Models\LearningPath;
use App\Models\LearningSpace;
use App\Models\User;

final class AssessmentProgress
{
    /**
     * Use the latest submitted attempt for each concept as a transparent progress
     * heuristic. A marked written response contributes its awarded points; an
     * unmarked response is not treated as a zero.
     *
     * @param  array<int, array{answered:int, correct:int, percentage:int, score_points:int, possible_points:int, written_points:int|null, written_max_points:int|null, written_status:string|null}>  $progress
     * @return array{total_concepts:int, practised_concepts:int, percentage:int, complete:bool}
     */
    public function pathSummary(LearningPath $path, array $progress): array
    {
        $total = $path->items->count();
        $practised = $path->items->filter(fn ($item) => isset($progress[$item->concept_id]))->count();

        return [
            'total_concepts' => $total,
            'practised_concepts' => $practised,
            'percentage' => $total === 0 ? 0 : (int) round(100 * $practised / $total),
            'complete' => $total > 0 && $practised === $total,
        ];
    }

    /** @return array<int, array{answered:int, correct:int, percentage:int, score_points:int, possible_points:int, written_points:int|null, written_max_points:int|null, written_status:string|null}> */
    public function forSpace(LearningSpace $space, User $user): array
    {
        return AssessmentAnswer::query()
            ->whereHas('attempt', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas('question', fn ($query) => $query->whereHas('assessment', fn ($assessment) => $assessment->where('learning_space_id', $space->id)))
            ->with(['question:id,concept_id,question_type,max_points', 'attempt:id,submitted_at'])
            ->get()
            ->groupBy(fn (AssessmentAnswer $answer) => $answer->question->concept_id)
            ->map(function ($conceptAnswers): array {
                $latestAttempt = $conceptAnswers->groupBy('assessment_attempt_id')
                    ->map(fn ($attemptAnswers) => [
                        'submitted_at' => $attemptAnswers->first()->attempt->submitted_at,
                        'answers' => $attemptAnswers,
                    ])
                    ->sortByDesc('submitted_at')
                    ->first();
                $answers = $latestAttempt['answers'];
                $multipleChoice = $answers->filter(fn (AssessmentAnswer $answer): bool => $answer->question->question_type === 'multiple_choice');
                $written = $answers->first(fn (AssessmentAnswer $answer): bool => $answer->question->question_type === 'open_ended');
                $writtenIsMarked = $written && $written->marking_status === 'marked' && $written->points_awarded !== null;
                $writtenPoints = $writtenIsMarked ? (int) $written->points_awarded : null;
                $writtenMax = $writtenIsMarked ? (int) ($written->max_points ?? $written->question->max_points ?? 0) : null;
                $correct = $multipleChoice->where('correct', true)->count();
                $possiblePoints = $multipleChoice->count() + ($writtenMax ?? 0);
                $scorePoints = $correct + ($writtenPoints ?? 0);

                return [
                    'answered' => $multipleChoice->count(),
                    'correct' => $correct,
                    'percentage' => $possiblePoints > 0 ? (int) round(100 * $scorePoints / $possiblePoints) : 0,
                    'score_points' => $scorePoints,
                    'possible_points' => $possiblePoints,
                    'written_points' => $writtenPoints,
                    'written_max_points' => $writtenMax,
                    'written_status' => $written?->marking_status,
                ];
            })
            ->all();
    }

    public function weakestPrerequisite(LearningSpace $space, int $conceptId, User $user): ?array
    {
        $concept = $space->concepts()->findOrFail($conceptId);
        $prerequisites = $concept->prerequisites()
            ->where('concepts.learning_space_id', $space->id)
            ->whereHas('sources', fn ($query) => $query->where('relevance_status', 'related')
                ->whereHas('material', fn ($material) => $material->where('learning_space_id', $space->id)->where('status', 'ready')))
            ->get();
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
