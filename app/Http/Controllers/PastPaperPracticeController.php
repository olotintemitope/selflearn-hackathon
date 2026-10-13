<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\PastPaperAttempt;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperQuestion;
use App\Services\Learning\PastPaperAnswerEvaluator;
use App\Services\Learning\PastPaperWrittenFeedbackException;
use App\Services\Learning\PastPaperWrittenFeedbackGenerator;
use App\Services\Learning\PastPaperWrittenFeedbackValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PastPaperPracticeController extends Controller
{
    public function startPaper(Request $request, Material $material)
    {
        $validated = $request->validate(['duration_minutes' => ['required', 'integer', 'in:30,60,90']]);
        $this->authorize('update', $material);
        abort_unless($material->role === 'past_paper' && $material->status === 'ready', 409);

        $questions = $this->readyQuestions($material);
        if ($questions->isEmpty()) {
            return back()->withErrors(['paper_practice' => 'There are no clear questions ready for practice yet. Check the detected questions against the original paper first.']);
        }

        $startedAt = now();
        $run = PastPaperPracticeRun::query()->create([
            'material_id' => $material->id,
            'user_id' => $request->user()->id,
            'question_ids' => $questions->modelKeys(),
            'started_at' => $startedAt,
            'duration_minutes' => $validated['duration_minutes'],
            'expires_at' => $startedAt->copy()->addMinutes((int) $validated['duration_minutes']),
        ]);

        return redirect()->route('past-paper-practice.show', [$material, $run]);
    }

    public function showPaper(Request $request, Material $material, PastPaperPracticeRun $run)
    {
        $this->authorize('view', $material);
        $this->assertRunOwner($request, $material, $run);
        if ($run->submitted_at) {
            return redirect()->route('past-paper-practice.results', $run);
        }

        if ($run->expires_at && now()->greaterThanOrEqualTo($run->expires_at)) {
            DB::transaction(function () use ($run): void {
                $lockedRun = PastPaperPracticeRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! $lockedRun->submitted_at && $lockedRun->expires_at && now()->greaterThanOrEqualTo($lockedRun->expires_at)) {
                    $lockedRun->update(['submitted_at' => now()]);
                }
            });

            return redirect()->route('past-paper-practice.results', $run);
        }

        $questions = $this->runQuestions($material, $run);
        $selectedMaterials = $this->selectedMaterials($material, $run);
        $isMixed = $selectedMaterials->count() > 1;
        $learningSpace = $material->learningSpace;

        return view('past-papers.run', compact('material', 'run', 'questions', 'selectedMaterials', 'isMixed', 'learningSpace'));
    }

    public function submitPaper(Request $request, Material $material, PastPaperPracticeRun $run, PastPaperAnswerEvaluator $evaluator)
    {
        $this->authorize('view', $material);
        $this->assertRunOwner($request, $material, $run);
        if ($run->submitted_at) {
            return redirect()->route('past-paper-practice.results', $run);
        }

        $expiredAtReceipt = $run->expires_at && now()->greaterThanOrEqualTo($run->expires_at);
        $validated = $expiredAtReceipt ? [] : $request->validate(['answers' => ['sometimes', 'array']]);
        $questions = $this->runQuestions($material, $run)->keyBy('id');
        $responses = [];

        foreach ($validated['answers'] ?? [] as $questionId => $response) {
            abort_unless(is_string($questionId) && $questions->has($questionId), 422, 'This answer does not belong to the selected paper.');
            abort_unless(is_array($response), 422, 'An answer has an invalid format.');

            if (array_key_exists('value', $response) && blank($response['value'])) {
                continue;
            }

            $question = $questions->get($questionId);
            $rules = match ($question->question_type) {
                'single_choice', 'true_false' => [
                    'value' => ['required', 'string', 'in:'.collect($question->options)->pluck('id')->implode(',')],
                ],
                'multiple_select' => [
                    'values' => ['required', 'array', 'min:1', 'max:'.max(1, count($question->options ?? []))],
                    'values.*' => ['required', 'string', 'distinct', 'in:'.collect($question->options)->pluck('id')->implode(',')],
                ],
                'fill_gap', 'structured_response' => ['value' => ['required', 'string', 'max:8000']],
                default => abort(422, 'This question format is not supported for practice.'),
            };

            $responses[$questionId] = Validator::make($response, $rules)->validate();
            if (isset($responses[$questionId]['value'])) {
                $responses[$questionId]['value'] = trim($responses[$questionId]['value']);
            }
        }

        DB::transaction(function () use ($run, $questions, $responses, $request, $evaluator): void {
            $lockedRun = PastPaperPracticeRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->submitted_at) {
                return;
            }

            $timedOut = $lockedRun->expires_at && now()->greaterThanOrEqualTo($lockedRun->expires_at);
            $responsesToSave = $timedOut ? [] : $responses;
            foreach ($responsesToSave as $questionId => $response) {
                $question = $questions->get($questionId);
                $result = $evaluator->evaluate($question, $response);
                $lockedRun->attempts()->create([
                    'past_paper_question_id' => $question->id,
                    'user_id' => $request->user()->id,
                    'response' => $response,
                    ...$result,
                    'submitted_at' => now(),
                ]);
            }

            $lockedRun->update(['submitted_at' => now()]);
        });

        return redirect()->route('past-paper-practice.results', $run);
    }

    public function paperResults(Request $request, PastPaperPracticeRun $run)
    {
        $run->load(['material', 'attempts.verifiedAnswer.markingSchemeMatch.markingScheme', 'attempts.verifiedAnswer.markingSchemeChunk']);
        $material = $run->material;
        $this->authorize('view', $material);
        abort_unless($run->user_id === $request->user()->id, 404);
        abort_unless($run->submitted_at, 404);

        $questions = $this->runQuestions($material, $run, allowStale: true);
        $selectedMaterials = $this->selectedMaterials($material, $run);
        $isMixed = $selectedMaterials->count() > 1;
        $learningSpace = $material->learningSpace;
        $attempts = $run->attempts->keyBy('past_paper_question_id');

        return view('past-papers.run-results', compact('material', 'run', 'questions', 'attempts', 'selectedMaterials', 'isMixed', 'learningSpace'));
    }

    public function show(Request $request, Material $material, PastPaperQuestion $question)
    {
        $this->authorize('view', $material);
        $this->assertCurrentCandidate($material, $question);
        abort_unless(in_array($question->review_status, PastPaperQuestion::PRACTICE_READY_STATUSES, true), 404);

        return view('past-papers.practice', [
            'material' => $material,
            'question' => $question,
            'attempt' => null,
            'sourceChunk' => $question->sourceChunk,
        ]);
    }

    public function submit(Request $request, Material $material, PastPaperQuestion $question, PastPaperAnswerEvaluator $evaluator, PastPaperWrittenFeedbackGenerator $writtenFeedback, PastPaperWrittenFeedbackValidator $writtenFeedbackValidator)
    {
        $this->authorize('view', $material);
        $this->assertCurrentCandidate($material, $question);
        abort_unless(in_array($question->review_status, PastPaperQuestion::PRACTICE_READY_STATUSES, true), 409, 'This question needs review before practice.');

        $validated = match ($question->question_type) {
            'single_choice', 'true_false' => $request->validate([
                'value' => ['required', 'string', 'in:'.collect($question->options)->pluck('id')->implode(',')],
            ]),
            'multiple_select' => $request->validate([
                'values' => ['required', 'array', 'min:1', 'max:'.max(1, count($question->options ?? []))],
                'values.*' => ['required', 'string', 'distinct', 'in:'.collect($question->options)->pluck('id')->implode(',')],
            ]),
            'fill_gap', 'structured_response' => $request->validate([
                'value' => ['required', 'string', 'max:8000'],
            ]),
            default => abort(422, 'This question format is not supported for practice.'),
        };

        $response = array_key_exists('values', $validated)
            ? ['values' => $validated['values']]
            : ['value' => trim($validated['value'])];
        $result = $evaluator->evaluate($question, $response);
        $attempt = PastPaperAttempt::query()->create([
            'past_paper_question_id' => $question->id,
            'user_id' => $request->user()->id,
            'response' => $response,
            ...$result,
            'submitted_at' => now(),
        ]);

        if (in_array($question->question_type, ['fill_gap', 'structured_response'], true)) {
            $this->addWrittenFeedback($attempt, $question, $material, $writtenFeedback, $writtenFeedbackValidator);
        }

        return redirect()->route('past-paper-attempts.show', $attempt);
    }

    public function result(Request $request, PastPaperAttempt $attempt)
    {
        $attempt->load(['question.material.learningSpace', 'question.sourceChunk', 'verifiedAnswer.markingSchemeMatch.markingScheme', 'verifiedAnswer.markingSchemeChunk']);
        $material = $attempt->question->material;
        $this->authorize('view', $material);
        abort_unless($attempt->user_id === $request->user()->id, 404);

        $writtenFeedbackEvidence = collect();
        if ($attempt->written_feedback_status === 'ready') {
            $citationIds = collect($attempt->written_feedback_source_chunk_ids ?? [])
                ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id): int => (int) $id)
                ->unique()->values();

            if ($citationIds->isEmpty() || $citationIds->contains(fn (int $id): bool => $id !== (int) $attempt->question->material_chunk_id)) {
                $attempt->setAttribute('written_feedback_status', 'unavailable');
            } else {
                $chunks = $material->chunks()
                    ->whereIn('id', $citationIds)
                    ->where('extraction_version', $attempt->question->extraction_version)
                    ->where('relevance_status', 'related')
                    ->get()->keyBy('id');

                if ($chunks->count() !== $citationIds->count()) {
                    $attempt->setAttribute('written_feedback_status', 'unavailable');
                } else {
                    $writtenFeedbackEvidence = $chunks->map(function ($chunk) use ($attempt, $material): array {
                        $sourceText = (string) $chunk->content;
                        $anchor = trim((string) $attempt->question->source_text);
                        $anchor = mb_substr($anchor, 0, 160);
                        $position = $anchor === '' ? false : mb_stripos($sourceText, $anchor);
                        $start = $position === false ? 0 : (int) $position;
                        $excerpt = mb_substr($sourceText, $start, 900);

                        return [
                            'title' => $material->title,
                            'page' => $chunk->page,
                            'excerpt' => $excerpt,
                            'continues' => $start + mb_strlen($excerpt) < mb_strlen($sourceText),
                        ];
                    })->values();
                }
            }
        }

        return view('past-papers.practice', [
            'material' => $material,
            'question' => $attempt->question,
            'attempt' => $attempt,
            'sourceChunk' => $attempt->question->sourceChunk,
            'writtenFeedbackEvidence' => $writtenFeedbackEvidence,
        ]);
    }

    private function assertCurrentCandidate(Material $material, PastPaperQuestion $question): void
    {
        abort_unless(
            $material->role === 'past_paper'
            && $material->status === 'ready'
            && $question->material_id === $material->id
            && $question->extraction_version === $material->extraction_version,
            404,
        );
    }

    private function addWrittenFeedback(PastPaperAttempt $attempt, PastPaperQuestion $question, Material $material, PastPaperWrittenFeedbackGenerator $generator, PastPaperWrittenFeedbackValidator $validator): void
    {
        $chunk = $question->sourceChunk;
        if (! $chunk
            || $chunk->material_id !== $material->id
            || $chunk->extraction_version !== $material->extraction_version
            || $chunk->relevance_status !== 'related'
            || trim($chunk->content) === '') {
            $attempt->update(['written_feedback_status' => 'unavailable']);

            return;
        }

        try {
            $result = $generator->generate(
                $question->prompt,
                $question->question_type,
                (string) ($attempt->response['value'] ?? ''),
                [['id' => (int) $chunk->id, 'content' => mb_substr($chunk->content, 0, 6000), 'page' => $chunk->page, 'material_title' => $material->title]],
            );
            $result = $validator->validate($result, [(int) $chunk->id]);
            $attempt->update([
                'written_feedback_status' => $result['status'],
                'written_feedback' => $result['feedback'],
                'written_feedback_steps' => $result['steps'],
                'written_feedback_source_chunk_ids' => $result['source_chunk_ids'],
            ]);
        } catch (PastPaperWrittenFeedbackException) {
            $attempt->update(['written_feedback_status' => 'unavailable']);
        }
    }

    private function assertRunOwner(Request $request, Material $material, PastPaperPracticeRun $run): void
    {
        abort_unless($run->material_id === $material->id, 404);
        abort_unless($run->user_id === $request->user()->id, 404);
        abort_unless($material->role === 'past_paper' && $material->status === 'ready', 404);
        $this->selectedMaterials($material, $run);
    }

    private function selectedMaterials(Material $material, PastPaperPracticeRun $run)
    {
        $ids = array_values(array_unique(array_map('intval', $run->selected_material_ids ?: [$run->material_id])));
        abort_unless($ids !== [] && $ids[0] === (int) $run->material_id, 404);

        $papersById = $material->learningSpace->materials()
            ->whereIn('id', $ids)
            ->where('role', 'past_paper')
            ->where('status', 'ready')
            ->get()
            ->keyBy('id');
        abort_unless($papersById->count() === count($ids), 404);

        return collect($ids)->map(fn (int $id) => $papersById->get($id));
    }

    private function readyQuestions(Material $material)
    {
        return $material->pastPaperQuestions()
            ->where('extraction_version', $material->extraction_version)
            ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
            ->get()
            ->sort(function (PastPaperQuestion $first, PastPaperQuestion $second): int {
                $pageOrder = ($first->page ?? PHP_INT_MAX) <=> ($second->page ?? PHP_INT_MAX);

                return $pageOrder !== 0 ? $pageOrder : strnatcasecmp($first->question_number, $second->question_number);
            })
            ->values();
    }

    private function runQuestions(Material $material, PastPaperPracticeRun $run, bool $allowStale = false)
    {
        $selectedMaterials = $this->selectedMaterials($material, $run);
        $materialIds = $selectedMaterials->pluck('id')->all();
        $positions = array_flip($run->question_ids ?? []);
        $questions = PastPaperQuestion::query()
            ->whereIn('material_id', $materialIds)
            ->whereIn('id', $run->question_ids ?? [])
            ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
            ->with(['sourceChunk', 'material'])
            ->get()
            ->filter(function (PastPaperQuestion $question) use ($selectedMaterials, $allowStale): bool {
                if ($allowStale) {
                    return true;
                }

                $sourcePaper = $selectedMaterials->firstWhere('id', $question->material_id);

                return $sourcePaper !== null && $sourcePaper->extraction_version === $question->extraction_version;
            })
            ->sortBy(fn (PastPaperQuestion $question): int => $positions[$question->id] ?? PHP_INT_MAX)
            ->values();

        abort_unless($questions->count() === count($run->question_ids ?? []), 409, 'A selected paper has changed since this practice started. Start a new set from the latest questions.');

        return $questions;
    }
}
