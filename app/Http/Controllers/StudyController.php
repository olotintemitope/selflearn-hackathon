<?php

namespace App\Http\Controllers;

use App\Models\LearningSpace;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\ChunkRetriever;
use App\Services\Learning\LearningPathBuilder;
use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\StudyGuideFallback;
use App\Services\Learning\StudyPathGuide;
use App\Services\Learning\Tutor;
use App\Services\Learning\TutorCheckProgress;
use App\Services\Learning\TutorException;
use App\Services\Learning\TutorFallbackCopy;
use Illuminate\Http\Request;

class StudyController extends Controller
{
    public function show(Request $request, LearningSpace $learningSpace, LearningPathBuilder $paths, AssessmentProgress $progressService, StudyPathGuide $guide, TutorCheckProgress $tutorChecks)
    {
        $this->authorizeSpace($request, $learningSpace);
        $path = $paths->build($learningSpace);
        $messages = $learningSpace->tutorMessages()->latest()->limit(20)->get()->reverse()->values();
        $retryQuestion = null;
        $latestMessage = $messages->last();
        if ($latestMessage?->role === 'assistant' && $latestMessage->status === 'failed') {
            $previousMessage = $messages->reverse()->skip(1)->first(fn ($message) => $message->role === 'user');
            $retryQuestion = $previousMessage?->content;
        }
        $assessmentProgress = $progressService->forSpace($learningSpace, $request->user());
        $tutorCheckProgress = $tutorChecks->forSpace($learningSpace);
        $pathProgress = $progressService->pathSummary($path, $assessmentProgress);
        $nextStep = $guide->nextStep($path, $assessmentProgress, $tutorCheckProgress);
        $suggestedTutorPrompt = null;
        $suggestedTutorConceptId = null;
        if ($request->query('concept') !== null) {
            $requestedConcept = $path->items->firstWhere('concept_id', (int) $request->query('concept'))?->concept;
            if ($requestedConcept) {
                $suggestedTutorConceptId = $requestedConcept->id;
                $suggestedTutorPrompt = ($tutorCheckProgress[$requestedConcept->id] ?? null) === 'needs_support'
                    ? 'I am still unsure about "'.$requestedConcept->name.'". Please explain just the next smallest idea in simpler words, using a short example grounded in my materials. Ask me one small question before moving on, and help me work it out without giving away the answer. Cite the source page and label anything beyond my sources as supplementary.'
                    : 'Teach me "'.$requestedConcept->name.'" in a short step-by-step lesson grounded in my uploaded materials. Start with a simple explanation, then connect the important ideas and explain why they matter in this subject or skill. Define key terms, people, processes, and dates only when my sources support them. Cite the source page for each important point. End with one short question to check my understanding. Label anything beyond my sources as supplementary.';
            }
        }

        return view('study.show', compact('learningSpace', 'path', 'messages', 'assessmentProgress', 'tutorCheckProgress', 'pathProgress', 'nextStep', 'suggestedTutorPrompt', 'suggestedTutorConceptId', 'retryQuestion'));
    }

    public function ask(Request $request, LearningSpace $learningSpace, ChunkRetriever $retriever, Tutor $tutor, StudyGuideFallback $fallback, TutorFallbackCopy $fallbackCopy)
    {
        $this->authorizeSpace($request, $learningSpace);
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'concept_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $question = trim($validated['question']);
        $targetConceptId = isset($validated['concept_id']) ? (int) $validated['concept_id'] : null;
        if ($targetConceptId !== null) {
            $targetConcept = $learningSpace->concepts()
                ->whereKey($targetConceptId)
                ->whereHas('sources', fn ($sources) => $sources->where('relevance_status', 'related')
                    ->whereHas('material', fn ($material) => $material->where('learning_space_id', $learningSpace->id)->where('status', 'ready')))
                ->firstOrFail();
            $targetConceptId = $targetConcept->id;
        }
        $desiredLanguage = $fallbackCopy->language($learningSpace->preferred_language);
        $conversation = $learningSpace->tutorMessages()
            ->whereIn('status', ['completed', 'fallback'])
            ->latest()->limit(20)->get()->reverse()
            ->filter(fn ($message) => $message->language === $desiredLanguage
                || ($desiredLanguage === 'en' && $message->language === null))
            ->take(6)
            ->map(fn ($message) => ['role' => $message->role, 'content' => mb_substr($message->content, 0, 1200)])
            ->values()->all();
        $learningSpace->tutorMessages()->create([
            'role' => 'user', 'content' => $question, 'citations' => [], 'supplementary_sections' => [],
            'language' => $desiredLanguage,
        ]);

        $chunks = $retriever->retrieve($learningSpace, $question, conceptId: $targetConceptId);
        if ($chunks->isEmpty()) {
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant',
                'content' => $fallbackCopy->text($desiredLanguage, 'no_evidence'),
                'citations' => [], 'supplementary_sections' => [], 'insufficient_evidence' => true,
                'prompt_version' => OpenAiTutor::PROMPT_VERSION,
                'concept_id' => $targetConceptId,
                'understanding_check' => 'not_applicable',
                'language' => $desiredLanguage,
            ]);

            return back()->with('status', $fallbackCopy->text($desiredLanguage, 'no_evidence_status'));
        }

        $evidence = $chunks->map(fn ($chunk) => [
            'id' => $chunk->id, 'content' => $chunk->content, 'page' => $chunk->page,
            'material_title' => $chunk->material->title,
        ])->all();

        try {
            $answer = $tutor->answer($question, $evidence, $learningSpace->preferred_language, $conversation);
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant', 'content' => $answer['answer'], 'citations' => $answer['citations'],
                'supplementary_sections' => $answer['supplementary'],
                'insufficient_evidence' => $answer['insufficient'], 'prompt_version' => OpenAiTutor::PROMPT_VERSION,
                'concept_id' => $targetConceptId,
                'understanding_check' => $answer['understandingCheck'] ?? 'not_applicable',
                'language' => $desiredLanguage,
            ]);
        } catch (TutorException $exception) {
            $guidedNote = $exception->getMessage() === 'The tutor returned an unresolved citation.'
                ? $fallback->forCitationFailure($learningSpace, $question, $chunks, $targetConceptId, $desiredLanguage)
                : null;

            if ($guidedNote) {
                $learningSpace->tutorMessages()->create([
                    'role' => 'assistant', 'content' => $guidedNote['content'],
                    'citations' => $guidedNote['citations'], 'supplementary_sections' => [],
                    'status' => 'fallback', 'prompt_version' => OpenAiTutor::PROMPT_VERSION,
                    'concept_id' => $guidedNote['concept_id'] ?? $targetConceptId,
                    'understanding_check' => 'not_applicable',
                    'language' => $desiredLanguage,
                ]);

                return back()->with('status', $fallbackCopy->text($desiredLanguage, 'citation_fallback_status'));
            }

            $failureMessage = $exception->getMessage() === 'The tutor returned an unresolved citation.'
                ? $fallbackCopy->text($desiredLanguage, 'citation_unavailable')
                : mb_substr($exception->getMessage(), 0, 500);
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant', 'content' => '', 'citations' => [], 'supplementary_sections' => [],
                'status' => 'failed', 'failure_message' => $failureMessage,
                'prompt_version' => OpenAiTutor::PROMPT_VERSION,
                'concept_id' => $targetConceptId,
                'language' => $desiredLanguage,
            ]);

            return back()->withInput();
        }

        return back();
    }

    private function authorizeSpace(Request $request, LearningSpace $learningSpace): void
    {
        abort_unless($learningSpace->user_id === $request->user()->id, 403);
    }
}
