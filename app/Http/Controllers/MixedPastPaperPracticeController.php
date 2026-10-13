<?php

namespace App\Http\Controllers;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\MaterialChunk;
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

        $chunksByMaterial = MaterialChunk::query()
            ->select('material_chunks.id', 'material_chunks.material_id', 'material_chunks.page')
            ->where('relevance_status', 'related')
            ->whereIn('material_id', $papers->modelKeys())
            ->whereHas('material', fn ($query) => $query
                ->where('learning_space_id', $learningSpace->id)
                ->whereColumn('materials.extraction_version', 'material_chunks.extraction_version'))
            ->with(['concepts' => fn ($query) => $query->where('learning_space_id', $learningSpace->id)])
            ->get()
            ->groupBy('material_id');

        $papers->each(function (Material $paper) use ($chunksByMaterial): void {
            $topics = $chunksByMaterial->get($paper->id, collect())
                ->flatMap(fn (MaterialChunk $chunk) => $chunk->concepts->map(fn ($concept) => [
                    'id' => $concept->id,
                    'name' => $concept->name,
                    'page' => $chunk->page,
                ]))
                ->groupBy('id')
                ->map(fn ($sources) => [
                    'name' => $sources->first()['name'],
                    'pages' => $sources->pluck('page')->filter()->unique()->sort()->values(),
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            $paper->setAttribute('related_topic_evidence', $topics);
        });

        return view('past-papers.mixed-select', compact('learningSpace', 'papers'));
    }

    public function start(Request $request, LearningSpace $learningSpace)
    {
        $this->authorizeSpace($request, $learningSpace);
        $validated = $request->validate([
            'material_ids' => ['required', 'array', 'min:2', 'max:12'],
            'material_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'duration_minutes' => ['required', 'integer', 'in:30,60,90'],
            'topic_grouping_confirmed' => ['required', 'accepted'],
        ], [
            'topic_grouping_confirmed.required' => 'Confirm that you want these selected papers in the same practice set.',
            'topic_grouping_confirmed.accepted' => 'Confirm that you want these selected papers in the same practice set.',
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
