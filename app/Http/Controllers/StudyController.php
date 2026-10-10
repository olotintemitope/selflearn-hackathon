<?php

namespace App\Http\Controllers;

use App\Models\LearningSpace;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\ChunkRetriever;
use App\Services\Learning\LearningPathBuilder;
use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\Tutor;
use App\Services\Learning\TutorException;
use Illuminate\Http\Request;

class StudyController extends Controller
{
    public function show(Request $request, LearningSpace $learningSpace, LearningPathBuilder $paths, AssessmentProgress $progressService)
    {
        $this->authorizeSpace($request, $learningSpace);
        $path = $paths->build($learningSpace);
        $messages = $learningSpace->tutorMessages()->latest()->limit(20)->get()->reverse()->values();
        $assessmentProgress = $progressService->forSpace($learningSpace, $request->user());

        return view('study.show', compact('learningSpace', 'path', 'messages', 'assessmentProgress'));
    }

    public function ask(Request $request, LearningSpace $learningSpace, ChunkRetriever $retriever, Tutor $tutor)
    {
        $this->authorizeSpace($request, $learningSpace);
        $validated = $request->validate(['question' => ['required', 'string', 'max:2000']]);
        $question = trim($validated['question']);
        $learningSpace->tutorMessages()->create([
            'role' => 'user', 'content' => $question, 'citations' => [], 'supplementary_sections' => [],
        ]);

        $chunks = $retriever->retrieve($learningSpace, $question);
        if ($chunks->isEmpty()) {
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant',
                'content' => 'I do not have enough evidence in your uploaded materials to answer that question.',
                'citations' => [], 'supplementary_sections' => [], 'insufficient_evidence' => true,
                'prompt_version' => OpenAiTutor::PROMPT_VERSION,
            ]);

            return back()->with('status', 'The tutor checked your sources but could not find enough evidence.');
        }

        $evidence = $chunks->map(fn ($chunk) => [
            'id' => $chunk->id, 'content' => $chunk->content, 'page' => $chunk->page,
            'material_title' => $chunk->material->title,
        ])->all();

        try {
            $answer = $tutor->answer($question, $evidence, $learningSpace->preferred_language);
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant', 'content' => $answer['answer'], 'citations' => $answer['citations'],
                'supplementary_sections' => $answer['supplementary'],
                'insufficient_evidence' => $answer['insufficient'], 'prompt_version' => OpenAiTutor::PROMPT_VERSION,
            ]);
        } catch (TutorException $exception) {
            $learningSpace->tutorMessages()->create([
                'role' => 'assistant', 'content' => '', 'citations' => [], 'supplementary_sections' => [],
                'status' => 'failed', 'failure_message' => mb_substr($exception->getMessage(), 0, 500),
                'prompt_version' => OpenAiTutor::PROMPT_VERSION,
            ]);
        }

        return back();
    }

    private function authorizeSpace(Request $request, LearningSpace $learningSpace): void
    {
        abort_unless($learningSpace->user_id === $request->user()->id, 403);
    }
}
