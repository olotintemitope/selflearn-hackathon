<?php

namespace App\Services\Learning;

use App\Models\LearningSpace;

final class TutorCheckProgress
{
    /** @return array<int, string> Latest qualitative tutor check for each path concept. */
    public function forSpace(LearningSpace $space): array
    {
        return $space->tutorMessages()
            ->where('role', 'assistant')
            ->whereNotNull('concept_id')
            ->whereIn('understanding_check', ['understood', 'developing', 'needs_support'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['concept_id', 'understanding_check'])
            ->unique('concept_id')
            ->mapWithKeys(fn ($message) => [$message->concept_id => $message->understanding_check])
            ->all();
    }
}
