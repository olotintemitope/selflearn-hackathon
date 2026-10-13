<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\MaterialChunk;
use App\Services\Learning\AnswerMarker;
use App\Services\Learning\AnswerMarkingException;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\EvidenceKnowledgeBasis;
use App\Services\Learning\LearningPathBuilder;
use App\Services\Learning\OpenEndedQuestionGenerationException;
use App\Services\Learning\OpenEndedQuestionGenerator;
use App\Services\Learning\QuizGenerationException;
use App\Services\Learning\QuizGenerator;
use App\Services\Learning\StudyPathGuide;
use App\Services\Learning\TutorCheckProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function store(Request $request, LearningSpace $learningSpace, Concept $concept, QuizGenerator $generator, OpenEndedQuestionGenerator $writtenGenerator)
    {
        $this->authorizeConcept($request, $learningSpace, $concept);
        $validated = $request->validate(['mode' => ['nullable', Rule::in(['guided', 'exam'])]]);
        $mode = $validated['mode'] ?? 'guided';
        $chunks = $concept->sources()
            ->whereHas('material', fn ($query) => $query->where('learning_space_id', $learningSpace->id)->where('status', 'ready'))
            ->where('relevance_status', 'related')
            ->with('material:id,learning_space_id,title')
            ->orderBy('material_chunks.id')
            ->limit(10)
            ->get();

        if ($chunks->isEmpty()) {
            return back()->withErrors(['practice' => 'This concept needs processed source material before practice questions can be created.']);
        }

        $evidence = $chunks->map(fn (MaterialChunk $chunk) => [
            'id' => $chunk->id,
            'content' => mb_substr($chunk->content, 0, 6000),
            'page' => $chunk->page,
            'material_title' => $chunk->material->title,
        ])->all();

        try {
            $questions = $generator->generate($concept->name, $evidence, 3, $mode);
            $writtenQuestion = $writtenGenerator->generate($concept->name, $evidence, $mode);
        } catch (QuizGenerationException $exception) {
            return back()->withErrors(['practice' => $this->learnerSafeGenerationError($exception->getMessage(), $mode)]);
        } catch (OpenEndedQuestionGenerationException $exception) {
            return back()->withErrors(['practice' => $this->learnerSafeGenerationError($exception->getMessage(), $mode)]);
        }

        $assessment = DB::transaction(function () use ($learningSpace, $concept, $questions, $writtenQuestion, $mode): Assessment {
            $assessment = $learningSpace->assessments()->create([
                'concept_id' => $concept->id,
                'prompt_version' => 'grounded-mixed-practice-v8',
                'mode' => $mode,
                'duration_seconds' => $mode === 'exam' ? 1200 : null,
            ]);

            foreach ($questions as $question) {
                $assessment->questions()->create([
                    'concept_id' => $concept->id,
                    'prompt' => $question['prompt'],
                    'knowledge_basis' => $question['knowledge_basis'],
                    'options' => $question['options'],
                    'correct_option_id' => $question['correct_option_id'],
                    'explanation' => $question['explanation'],
                    'solution_steps' => $question['solution_steps'],
                    'source_refs' => $question['source_chunk_ids'],
                ]);
            }

            $assessment->questions()->create([
                'concept_id' => $concept->id,
                'question_type' => 'open_ended',
                'knowledge_basis' => $writtenQuestion['knowledge_basis'],
                'prompt' => $writtenQuestion['prompt'],
                'options' => [],
                'correct_option_id' => '',
                'explanation' => '',
                'source_refs' => $writtenQuestion['source_chunk_ids'],
                'rubric' => $writtenQuestion['rubric'],
                'max_points' => $writtenQuestion['max_points'],
                'solution_steps' => $writtenQuestion['solution_steps'],
            ]);

            return $assessment;
        });

        return redirect()->route('assessments.show', $assessment);
    }

    public function show(Request $request, Assessment $assessment, AssessmentProgress $progressService, LearningPathBuilder $paths, StudyPathGuide $guide, TutorCheckProgress $tutorChecks)
    {
        $this->authorizeAssessment($request, $assessment);
        $assessment->load(['concept', 'questions']);
        $attempt = null;
        if ($request->filled('attempt')) {
            $attempt = $assessment->attempts()->where('user_id', $request->user()->id)
                ->with('answers')->findOrFail($request->query('attempt'));
        }

        $sourceIds = $assessment->questions->flatMap(fn ($question) => $question->source_refs)->unique();
        $sources = MaterialChunk::query()->whereIn('id', $sourceIds)
            ->whereHas('material', fn ($query) => $query->where('learning_space_id', $assessment->learning_space_id))
            ->with('material:id,title')->get()->keyBy('id');
        $this->applyEffectiveKnowledgeBasis($assessment->questions, $sources);
        if ($assessment->mode === 'exam' && ! $attempt
            && $assessment->questions->contains(fn (AssessmentQuestion $question): bool => $question->knowledge_basis !== 'source_supported')) {
            return redirect()->route('study.show', $assessment->learningSpace)
                ->withErrors(['practice' => 'This saved exam includes questions that go beyond your source. It was not started. Add clearer material or create a fresh exam from supported evidence.']);
        }
        if ($assessment->mode === 'exam' && ! $assessment->started_at) {
            $assessment->forceFill(['started_at' => now()])->save();
        }
        $examSecondsRemaining = $assessment->mode === 'exam'
            ? max(0, (int) ceil($assessment->duration_seconds - $assessment->started_at->diffInSeconds(now())))
            : null;
        $recommendation = $attempt && $attempt->correct_count < $attempt->question_count
            ? $progressService->weakestPrerequisite($assessment->learningSpace, $assessment->concept_id, $request->user())
            : null;
        $currentProgress = null;
        $recommendedStep = null;
        if ($attempt) {
            $allProgress = $progressService->forSpace($assessment->learningSpace, $request->user());
            $currentProgress = $allProgress[$assessment->concept_id] ?? null;
            $studyPath = $paths->build($assessment->learningSpace);
            $recommendedStep = $guide->nextStep($studyPath, $allProgress, $tutorChecks->forSpace($assessment->learningSpace));
        }

        $multipleChoiceCount = $assessment->questions->where('question_type', 'multiple_choice')->count();
        $outdatedQuestionVersion = $assessment->prompt_version !== 'grounded-mixed-practice-v8';
        $attemptTimedOut = $attempt !== null
            && $assessment->mode === 'exam'
            && $assessment->started_at !== null
            && $attempt->submitted_at->greaterThanOrEqualTo($assessment->started_at->copy()->addSeconds($assessment->duration_seconds));

        return view('assessments.show', compact('assessment', 'attempt', 'sources', 'recommendation', 'currentProgress', 'recommendedStep', 'multipleChoiceCount', 'outdatedQuestionVersion', 'examSecondsRemaining', 'attemptTimedOut'));
    }

    public function submit(Request $request, Assessment $assessment, AnswerMarker $marker)
    {
        $this->authorizeAssessment($request, $assessment);
        $validated = $request->validate(['attempt_uuid' => ['required', 'uuid']]);
        $assessment->refresh();
        $priorAttempt = AssessmentAttempt::query()->where('user_id', $request->user()->id)
            ->where('client_attempt_uuid', $validated['attempt_uuid'])->first();
        if ($priorAttempt) {
            abort_unless($priorAttempt->assessment_id === $assessment->id, 409);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'synced',
                    'attempt_id' => $priorAttempt->id,
                    'result_url' => route('assessments.show', ['assessment' => $assessment, 'attempt' => $priorAttempt->id]),
                ]);
            }

            return redirect()->route('assessments.show', ['assessment' => $assessment, 'attempt' => $priorAttempt->id]);
        }

        $questions = $assessment->questions()->get();
        $sourceIds = $questions->flatMap(fn ($question) => $question->source_refs)->unique()->values();
        $sourceChunks = MaterialChunk::query()->whereIn('id', $sourceIds)
            ->where('relevance_status', 'related')
            ->whereHas('material', fn ($query) => $query
                ->where('learning_space_id', $assessment->learning_space_id)
                ->where('status', 'ready'))
            ->get()->keyBy('id');
        $this->applyEffectiveKnowledgeBasis($questions, $sourceChunks);
        if ($assessment->mode === 'exam'
            && $questions->contains(fn (AssessmentQuestion $question): bool => $question->knowledge_basis !== 'source_supported')) {
            $message = 'This saved exam includes questions that go beyond your source. It was not submitted. Add clearer material or create a fresh exam from supported evidence.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : redirect()->route('study.show', $assessment->learningSpace)->withErrors(['practice' => $message]);
        }
        if ($assessment->mode === 'exam' && ! $assessment->started_at) {
            $assessment->forceFill(['started_at' => now()])->save();
        }
        $examExpired = $assessment->mode === 'exam'
            && now()->greaterThanOrEqualTo($assessment->started_at->copy()->addSeconds($assessment->duration_seconds));
        $multipleChoice = $questions->where('question_type', 'multiple_choice');
        $written = $questions->firstWhere('question_type', 'open_ended');
        $answers = $examExpired ? [] : $request->input('answers', []);
        if (! is_array($answers) || ! $written) {
            return back()->withErrors(['answers' => 'Answer every question before submitting.'])->withInput();
        }

        $writtenResponse = '';
        if (! $examExpired) {
            $rules = [];
            foreach ($multipleChoice as $question) {
                $rules['answers.'.$question->id] = ['required', Rule::in(collect($question->options)->pluck('id')->all())];
            }
            $rules['responses.'.$written->id] = [$request->expectsJson() ? 'nullable' : 'required', 'string', 'max:12000'];
            Validator::make($request->all(), $rules)->validate();
            $writtenResponse = (string) $request->input('responses.'.$written->id, '');
        }

        [$attempt, $created] = DB::transaction(function () use ($request, $assessment, $multipleChoice, $written, $answers, $writtenResponse, $validated): array {
            $lockedAssessment = Assessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            $competingAttempt = AssessmentAttempt::query()
                ->where('user_id', $request->user()->id)
                ->where('client_attempt_uuid', $validated['attempt_uuid'])
                ->first();
            if ($competingAttempt) {
                abort_unless($competingAttempt->assessment_id === $lockedAssessment->id, 409);

                return [$competingAttempt, false];
            }

            $timedOut = $lockedAssessment->mode === 'exam'
                && $lockedAssessment->started_at !== null
                && now()->greaterThanOrEqualTo($lockedAssessment->started_at->copy()->addSeconds($lockedAssessment->duration_seconds));
            $submittedAnswers = $timedOut ? [] : $answers;
            $submittedWrittenResponse = $timedOut ? '' : $writtenResponse;
            $correctCount = $multipleChoice->filter(fn ($question) => ($submittedAnswers[$question->id] ?? null) === $question->correct_option_id)->count();
            $attempt = $assessment->attempts()->create([
                'user_id' => $request->user()->id,
                'client_attempt_uuid' => $validated['attempt_uuid'],
                'correct_count' => $correctCount,
                'question_count' => $multipleChoice->count(),
                'submitted_at' => now(),
            ]);

            foreach ($multipleChoice as $question) {
                $attempt->answers()->create([
                    'assessment_question_id' => $question->id,
                    'selected_option_id' => $submittedAnswers[$question->id] ?? '',
                    'correct' => ($submittedAnswers[$question->id] ?? null) === $question->correct_option_id,
                ]);
            }

            $attempt->answers()->create([
                'assessment_question_id' => $written->id,
                // These legacy MCQ columns remain required by the original schema.
                'selected_option_id' => '',
                'correct' => false,
                'written_response' => $submittedWrittenResponse,
                'marking_status' => 'pending',
                'max_points' => $written->max_points,
            ]);

            return [$attempt, true];
        });

        if ($created) {
            $this->markWrittenAnswer($attempt->fresh('answers'), $written, $marker);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'synced',
                'attempt_id' => $attempt->id,
                'result_url' => route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]),
            ], $created ? 201 : 200);
        }

        return redirect()->route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]);
    }

    public function markOpenEnded(Request $request, Assessment $assessment, AssessmentAttempt $attempt, AnswerMarker $marker)
    {
        $this->authorizeAssessment($request, $assessment);
        abort_unless($attempt->assessment_id === $assessment->id && $attempt->user_id === $request->user()->id, 404);
        $answer = $attempt->answers()->where('marking_status', 'failed')->whereNotNull('written_response')->firstOrFail();
        $question = $assessment->questions()->findOrFail($answer->assessment_question_id);
        $this->markWrittenAnswer($attempt, $question, $marker);

        return redirect()->route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]);
    }

    private function markWrittenAnswer(AssessmentAttempt $attempt, AssessmentQuestion $question, AnswerMarker $marker): void
    {
        $answer = $attempt->answers()->where('assessment_question_id', $question->id)->firstOrFail();
        if (trim((string) $answer->written_response) === '') {
            $answer->update([
                'marking_status' => 'not_applicable', 'points_awarded' => null,
                'marking_criteria_results' => [], 'marking_citations' => [],
                'marking_feedback' => 'No written response was submitted, so no written score was assigned.',
            ]);

            return;
        }

        $sourceIds = collect($question->source_refs)->map(fn ($id) => (int) $id)->all();
        $chunks = MaterialChunk::query()->whereIn('id', $sourceIds)
            ->whereHas('material', fn ($query) => $query->where('learning_space_id', $attempt->assessment->learning_space_id)->where('status', 'ready'))
            ->where('relevance_status', 'related')
            ->with('material:id,title')->get()->keyBy('id');
        if (count($chunks) !== count(array_unique($sourceIds))) {
            $answer->update(['marking_status' => 'needs_review', 'points_awarded' => null, 'marking_criteria_results' => [], 'marking_citations' => [], 'marking_feedback' => 'Some supporting material is unavailable, so this answer was not scored. Add the relevant material and create a fresh practice set.']);

            return;
        }
        $evidence = $chunks->map(fn (MaterialChunk $chunk) => [
            'id' => $chunk->id, 'content' => mb_substr($chunk->content, 0, 6000),
            'page' => $chunk->page, 'material_title' => $chunk->material->title,
        ])->values()->all();

        try {
            $result = $marker->mark($question->prompt, $question->knowledge_basis, $question->rubric ?? [], $answer->written_response, $evidence);
            $answer->update([
                'marking_status' => $result['status'], 'points_awarded' => $result['points_awarded'],
                'marking_feedback' => $result['feedback'], 'marking_criteria_results' => $result['criteria_results'],
                'marking_citations' => collect($result['criteria_results'])->flatMap(fn ($item) => $item['source_chunk_ids'])->unique()->values()->all(),
            ]);
        } catch (AnswerMarkingException) {
            $answer->update([
                'marking_status' => 'failed', 'points_awarded' => null,
                'marking_feedback' => 'Your response was saved, but AI marking is temporarily unavailable. Try again later or check it against an official marking scheme.',
            ]);
        }
    }

    private function applyEffectiveKnowledgeBasis($questions, $sourceChunks): void
    {
        $guard = new EvidenceKnowledgeBasis;
        foreach ($questions as $question) {
            $questionSources = collect($question->source_refs)->map(fn ($id) => $sourceChunks->get($id))
                ->filter()->map(fn (MaterialChunk $chunk) => ['content' => $chunk->content])->all();
            $question->setAttribute('knowledge_basis', $guard->forQuestion([
                'knowledge_basis' => $question->knowledge_basis,
                'prompt' => $question->prompt,
                'explanation' => $question->explanation,
                'options' => $question->options ?? [],
                'solution_steps' => $question->solution_steps ?? [],
                'rubric' => $question->rubric ?? [],
            ], $questionSources));
        }
    }

    private function authorizeConcept(Request $request, LearningSpace $space, Concept $concept): void
    {
        abort_unless($space->user_id === $request->user()->id && $concept->learning_space_id === $space->id, 404);
    }

    private function authorizeAssessment(Request $request, Assessment $assessment): void
    {
        abort_unless($assessment->learningSpace()->where('user_id', $request->user()->id)->exists(), 404);
    }

    private function learnerSafeGenerationError(string $message, string $mode): string
    {
        if ($mode === 'exam' && (str_contains($message, 'provider returned') || str_contains($message, 'does not support a fair source-based'))) {
            return 'I could not build a fair exam from clear evidence in your materials. Review the source material, try guided practice, or add a clearer page, then start a new exam.';
        }

        return $message;
    }
}
