<?php

namespace App\Http\Controllers;

use App\Models\LearningSpace;
use App\Models\MaterialChunk;
use App\Services\Learning\LearningPathBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineStudyPackController extends Controller
{
    public function show(Request $request, LearningSpace $learningSpace, LearningPathBuilder $paths): JsonResponse
    {
        abort_unless($learningSpace->user_id === $request->user()->id, 403);

        $path = $paths->build($learningSpace);
        $items = $path->items()->with([
            'concept.prerequisites',
            'concept.sources' => fn ($query) => $query->where('relevance_status', 'related')
                ->whereHas('material', fn ($materials) => $materials
                    ->where('learning_space_id', $learningSpace->id)
                    ->where('status', 'ready')),
            'concept.sources.material',
        ])->get()->filter(fn ($item) => $item->concept !== null);

        $conceptIds = $items->pluck('concept_id')->all();
        $assessments = $learningSpace->assessments()
            ->with(['concept:id,name', 'questions' => fn ($query) => $query->whereIn('concept_id', $conceptIds)])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($assessment) {
                if ($assessment->mode !== 'guided') {
                    return null;
                }

                $questions = $assessment->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'type' => $question->question_type,
                    'options' => collect($question->options)->filter(fn ($option) => is_array($option))
                        ->map(fn (array $option) => ['id' => $option['id'] ?? null, 'text' => $option['text'] ?? ''])
                        ->values(),
                ])->values();

                $objectiveCount = $questions->where('type', 'multiple_choice')->count();
                $writtenCount = $questions->where('type', 'open_ended')->count();
                if ($objectiveCount < 1 || $writtenCount !== 1 || $questions->count() !== $objectiveCount + 1) {
                    return null;
                }

                return [
                    'id' => $assessment->id,
                    'title' => $assessment->concept?->name ?? 'Study practice',
                    'mode' => $assessment->mode,
                    'duration_seconds' => $assessment->duration_seconds,
                    'submit_url' => route('assessments.submit', $assessment),
                    'result_url' => route('assessments.show', $assessment),
                    'questions' => $questions,
                ];
            })->filter()->values();

        $explanations = $learningSpace->tutorMessages()
            ->where('role', 'assistant')
            ->whereIn('status', ['completed', 'fallback'])
            ->latest()->limit(30)->get()->reverse()->values()
            ->map(fn ($message) => [
                'content' => $message->content,
                'citations' => collect($message->citations)->map(function (array $citation) use ($learningSpace) {
                    if (! isset($citation['chunk_id'])) {
                        return null;
                    }

                    $chunk = MaterialChunk::query()
                        ->with('material')
                        ->whereKey($citation['chunk_id'])
                        ->whereHas('material', fn ($materials) => $materials->where('learning_space_id', $learningSpace->id))
                        ->first();

                    return $chunk ? [
                        'material' => $chunk->material->title,
                        'page' => $chunk->page,
                        'excerpt' => $citation['excerpt'] ?? '',
                    ] : null;
                })->filter()->values(),
                'supplementary' => $message->supplementary_sections,
            ]);

        return response()->json([
            'version' => (int) $path->version,
            'space' => ['id' => (string) $learningSpace->getRouteKey(), 'title' => $learningSpace->title, 'goal' => $learningSpace->goal],
            'generated_at' => now()->toIso8601String(),
            'concepts' => $items->values()->map(fn ($item) => [
                'id' => $item->concept_id,
                'position' => $item->position,
                'name' => $item->concept->name,
                'description' => $item->concept->description,
                'prerequisites' => $item->concept->prerequisites->pluck('name')->values(),
                'sources' => $item->concept->sources->map(fn ($source) => [
                    'material' => $source->material->title,
                    'page' => $source->page,
                    'excerpt' => mb_substr($source->content, 0, 1800),
                ])->values(),
            ]),
            'assessments' => $assessments,
            'explanations' => $explanations,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
