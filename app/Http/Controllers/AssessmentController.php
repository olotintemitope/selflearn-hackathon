<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\MaterialChunk;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\OpenAiQuizGenerator;
use App\Services\Learning\QuizGenerationException;
use App\Services\Learning\QuizGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function store(Request $request, LearningSpace $learningSpace, Concept $concept, QuizGenerator $generator)
    {
        $this->authorizeConcept($request, $learningSpace, $concept);
        $chunks = $concept->sources()
            ->whereHas('material', fn ($query) => $query->where('learning_space_id', $learningSpace->id)->where('status', 'ready'))
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
            $questions = $generator->generate($concept->name, $evidence, 3);
        } catch (QuizGenerationException $exception) {
            return back()->withErrors(['practice' => $exception->getMessage()]);
        }

        $assessment = DB::transaction(function () use ($learningSpace, $concept, $questions): Assessment {
            $assessment = $learningSpace->assessments()->create([
                'concept_id' => $concept->id,
                'prompt_version' => OpenAiQuizGenerator::PROMPT_VERSION,
            ]);

            foreach ($questions as $question) {
                $assessment->questions()->create([
                    'concept_id' => $concept->id,
                    'prompt' => $question['prompt'],
                    'options' => $question['options'],
                    'correct_option_id' => $question['correct_option_id'],
                    'explanation' => $question['explanation'],
                    'source_refs' => $question['source_chunk_ids'],
                ]);
            }

            return $assessment;
        });

        return redirect()->route('assessments.show', $assessment);
    }

    public function show(Request $request, Assessment $assessment, AssessmentProgress $progressService)
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
        $recommendation = $attempt && $attempt->correct_count < $attempt->question_count
            ? $progressService->weakestPrerequisite($assessment->learningSpace, $assessment->concept_id, $request->user())
            : null;

        return view('assessments.show', compact('assessment', 'attempt', 'sources', 'recommendation'));
    }

    public function submit(Request $request, Assessment $assessment)
    {
        $this->authorizeAssessment($request, $assessment);
        $validated = $request->validate(['attempt_uuid' => ['required', 'uuid']]);
        $priorAttempt = AssessmentAttempt::query()->where('user_id', $request->user()->id)
            ->where('client_attempt_uuid', $validated['attempt_uuid'])->first();
        if ($priorAttempt) {
            abort_unless($priorAttempt->assessment_id === $assessment->id, 409);

            return redirect()->route('assessments.show', ['assessment' => $assessment, 'attempt' => $priorAttempt->id]);
        }

        $questions = $assessment->questions()->get();
        $answers = $request->input('answers');
        if (! is_array($answers) || count($answers) !== $questions->count()) {
            return back()->withErrors(['answers' => 'Answer every question before submitting.'])->withInput();
        }

        $rules = [];
        foreach ($questions as $question) {
            $rules['answers.'.$question->id] = ['required', Rule::in(collect($question->options)->pluck('id')->all())];
        }
        Validator::make($request->all(), $rules)->validate();

        $attempt = DB::transaction(function () use ($request, $assessment, $questions, $answers, $validated): AssessmentAttempt {
            $correctCount = $questions->filter(fn ($question) => $answers[$question->id] === $question->correct_option_id)->count();
            $attempt = $assessment->attempts()->create([
                'user_id' => $request->user()->id,
                'client_attempt_uuid' => $validated['attempt_uuid'],
                'correct_count' => $correctCount,
                'question_count' => $questions->count(),
                'submitted_at' => now(),
            ]);

            foreach ($questions as $question) {
                $attempt->answers()->create([
                    'assessment_question_id' => $question->id,
                    'selected_option_id' => $answers[$question->id],
                    'correct' => $answers[$question->id] === $question->correct_option_id,
                ]);
            }

            return $attempt;
        });

        return redirect()->route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]);
    }

    private function authorizeConcept(Request $request, LearningSpace $space, Concept $concept): void
    {
        abort_unless($space->user_id === $request->user()->id && $concept->learning_space_id === $space->id, 404);
    }

    private function authorizeAssessment(Request $request, Assessment $assessment): void
    {
        abort_unless($assessment->learningSpace()->where('user_id', $request->user()->id)->exists(), 404);
    }
}
