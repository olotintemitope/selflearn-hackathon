<?php

namespace App\Http\Controllers;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperQuestion;
use Illuminate\Http\Request;

class MixedPastPaperPracticeController extends Controller
{
    public function index(Request $request, LearningSpace $learningSpace)
    {
        $this->authorizeSpace($request, $learningSpace);
        $papers = $learningSpace->materials()
            ->where('role', 'past_paper')
            ->where('status', 'ready')
            ->latest()
            ->get()
            ->map(function (Material $material): Material {
                $material->setAttribute('ready_question_count', $material->pastPaperQuestions()
                    ->where('extraction_version', $material->extraction_version)
                    ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
                    ->count());
                $material->setAttribute('paper_year_hint', $material->pastPaperQuestions()
                    ->where('extraction_version', $material->extraction_version)
                    ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
                    ->whereNotNull('paper_year')
                    ->value('paper_year'));

                return $material;
            })
            ->filter(fn (Material $material): bool => $material->ready_question_count > 0)
            ->values();

        return view('past-papers.mixed-select', compact('learningSpace', 'papers'));
    }

    public function start(Request $request, LearningSpace $learningSpace)
    {
        $this->authorizeSpace($request, $learningSpace);
        $validated = $request->validate([
            'material_ids' => ['required', 'array', 'min:2', 'max:12'],
            'material_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'duration_minutes' => ['required', 'integer', 'in:30,60,90'],
        ]);

        $submittedIds = array_map('intval', $validated['material_ids']);
        $papersById = $learningSpace->materials()
            ->whereIn('id', $submittedIds)
            ->where('role', 'past_paper')
            ->where('status', 'ready')
            ->get()
            ->keyBy('id');

        if ($papersById->count() !== count(array_unique($submittedIds))) {
            return back()->withErrors(['material_ids' => 'Choose at least two ready past papers from this study space.']);
        }

        $papers = collect($submittedIds)->map(fn (int $id) => $papersById->get($id));
        $questions = collect();
        foreach ($papers as $paper) {
            $paperQuestions = $this->readyQuestions($paper);
            if ($paperQuestions->isEmpty()) {
                return back()->withErrors(['material_ids' => 'Each selected paper needs at least one clear question ready to practise. Your paper list has not changed.']);
            }
            $questions = $questions->concat($paperQuestions);
        }

        $startedAt = now();
        $run = PastPaperPracticeRun::query()->create([
            'material_id' => $papers->first()->id,
            'selected_material_ids' => $papers->pluck('id')->all(),
            'user_id' => $request->user()->id,
            'question_ids' => $questions->pluck('id')->all(),
            'started_at' => $startedAt,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'expires_at' => $startedAt->copy()->addMinutes((int) $validated['duration_minutes']),
        ]);

        return redirect()->route('past-paper-practice.show', [$papers->first(), $run]);
    }

    private function readyQuestions(Material $paper)
    {
        return $paper->pastPaperQuestions()
            ->where('extraction_version', $paper->extraction_version)
            ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
            ->get()
            ->sort(function (PastPaperQuestion $first, PastPaperQuestion $second): int {
                $pageOrder = ($first->page ?? PHP_INT_MAX) <=> ($second->page ?? PHP_INT_MAX);

                return $pageOrder !== 0 ? $pageOrder : strnatcasecmp($first->question_number, $second->question_number);
            })
            ->values();
    }

    private function authorizeSpace(Request $request, LearningSpace $learningSpace): void
    {
        abort_unless($learningSpace->user_id === $request->user()->id, 403);
    }
}
