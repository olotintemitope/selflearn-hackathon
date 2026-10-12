<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\PastPaperAttempt;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperQuestion;
use App\Services\Learning\PastPaperAnswerEvaluator;
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

        return view('past-papers.run', compact('material', 'run', 'questions'));
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
        $run->load(['material', 'attempts']);
        $material = $run->material;
        $this->authorize('view', $material);
        abort_unless($run->user_id === $request->user()->id, 404);
        abort_unless($run->submitted_at, 404);

        $questions = $this->runQuestions($material, $run, allowStale: true);
        $attempts = $run->attempts->keyBy('past_paper_question_id');

        return view('past-papers.run-results', compact('material', 'run', 'questions', 'attempts'));
    }

    public function show(Request $request, Material $material, PastPaperQuestion $question)
    {
        $this->authorize('view', $material);
        $this->assertCurrentCandidate($material, $question);
        abort_unless($question->review_status === 'ready', 404);

        return view('past-papers.practice', [
            'material' => $material,
            'question' => $question,
            'attempt' => null,
            'sourceChunk' => $question->sourceChunk,
        ]);
    }

    public function submit(Request $request, Material $material, PastPaperQuestion $question, PastPaperAnswerEvaluator $evaluator)
    {
        $this->authorize('view', $material);
        $this->assertCurrentCandidate($material, $question);
        abort_unless($question->review_status === 'ready', 409, 'This question needs review before practice.');

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

        return redirect()->route('past-paper-attempts.show', $attempt);
    }

    public function result(Request $request, PastPaperAttempt $attempt)
    {
        $attempt->load(['question.material.learningSpace', 'question.sourceChunk']);
        $material = $attempt->question->material;
        $this->authorize('view', $material);
        abort_unless($attempt->user_id === $request->user()->id, 404);

        return view('past-papers.practice', [
            'material' => $material,
            'question' => $attempt->question,
            'attempt' => $attempt,
            'sourceChunk' => $attempt->question->sourceChunk,
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

    private function assertRunOwner(Request $request, Material $material, PastPaperPracticeRun $run): void
    {
        abort_unless($run->material_id === $material->id, 404);
        abort_unless($run->user_id === $request->user()->id, 404);
        abort_unless($material->role === 'past_paper' && $material->status === 'ready', 404);
    }

    private function readyQuestions(Material $material)
    {
        return $material->pastPaperQuestions()
            ->where('extraction_version', $material->extraction_version)
            ->where('review_status', 'ready')
            ->get()
            ->sort(function (PastPaperQuestion $first, PastPaperQuestion $second): int {
                $pageOrder = ($first->page ?? PHP_INT_MAX) <=> ($second->page ?? PHP_INT_MAX);

                return $pageOrder !== 0 ? $pageOrder : strnatcasecmp($first->question_number, $second->question_number);
            })
            ->values();
    }

    private function runQuestions(Material $material, PastPaperPracticeRun $run, bool $allowStale = false)
    {
        $positions = array_flip($run->question_ids ?? []);
        $questions = $material->pastPaperQuestions()
            ->whereIn('id', $run->question_ids ?? [])
            ->where('review_status', 'ready')
            ->when(! $allowStale, fn ($query) => $query->where('extraction_version', $material->extraction_version))
            ->with('sourceChunk')
            ->get()
            ->sortBy(fn (PastPaperQuestion $question): int => $positions[$question->id] ?? PHP_INT_MAX)
            ->values();

        abort_unless($questions->count() === count($run->question_ids ?? []), 409, 'This paper has changed since the practice session started. Start a new session from the latest detected questions.');

        return $questions;
    }
}
