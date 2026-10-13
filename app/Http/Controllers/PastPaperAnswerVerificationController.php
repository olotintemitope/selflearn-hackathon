<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\PastPaperMarkingSchemeMatch;
use App\Models\PastPaperQuestion;
use App\Models\PastPaperVerifiedAnswer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PastPaperAnswerVerificationController extends Controller
{
    public function create(Request $request, Material $material, PastPaperQuestion $question): View|RedirectResponse
    {
        $this->authorize('update', $material);
        $this->assertEligibleQuestion($material, $question);

        $matches = $this->currentMatches($material);
        if ($matches->isEmpty()) {
            return redirect()->route('materials.show', $material)
                ->withErrors(['answer_key' => 'Link a ready answer key to this paper before checking an answer.']);
        }

        $matchId = filter_var($request->query('match'), FILTER_VALIDATE_INT);
        $match = $matches->firstWhere('id', $matchId) ?? $matches->first();
        $pages = $match->markingScheme->chunks()
            ->where('extraction_version', $match->marking_scheme_extraction_version)
            ->whereNotNull('page')
            ->orderBy('page')
            ->get()
            ->groupBy('page');

        $requestedPage = filter_var($request->query('page'), FILTER_VALIDATE_INT);
        $page = $pages->has($requestedPage) ? (int) $requestedPage : $pages->keys()->first();
        $answerKeyChunks = $page === null ? collect() : $pages->get($page, collect());
        $questionChunk = $material->chunks()
            ->whereKey($question->material_chunk_id)
            ->where('extraction_version', $question->extraction_version)
            ->first();
        $verifiedAnswer = $question->verifiedAnswers()
            ->where('marking_scheme_match_id', $match->id)
            ->where('paper_extraction_version', $material->extraction_version)
            ->where('marking_scheme_extraction_version', $match->marking_scheme_extraction_version)
            ->orderByDesc('confirmation_version')
            ->first();

        return view('past-papers.verify-answer', compact(
            'material', 'question', 'matches', 'match', 'pages', 'page', 'answerKeyChunks', 'questionChunk', 'verifiedAnswer',
        ));
    }

    public function store(Request $request, Material $material, PastPaperQuestion $question): RedirectResponse
    {
        $this->authorize('update', $material);
        $this->assertEligibleQuestion($material, $question);

        $validOptionIds = collect($question->options ?? [])->pluck('id')->filter()->values()->all();
        $validated = $request->validate([
            'marking_scheme_match_id' => ['required', 'integer'],
            'source_page' => ['required', 'integer', 'min:1'],
            'answer_option_ids' => ['required', 'array', 'min:1', 'max:'.count($validOptionIds)],
            'answer_option_ids.*' => ['required', 'string', 'distinct', Rule::in($validOptionIds)],
            'answer_excerpt' => ['required', 'string', 'max:1500'],
            'confirm_sources' => ['accepted'],
        ]);

        $match = $this->currentMatches($material)->firstWhere('id', (int) $validated['marking_scheme_match_id']);
        abort_unless($match instanceof PastPaperMarkingSchemeMatch, 404);

        $optionIds = collect($validated['answer_option_ids'])->sort()->values()->all();
        if ($question->question_type !== 'multiple_select' && count($optionIds) !== 1) {
            return back()->withErrors(['answer_option_ids' => 'Choose one answer for this question.'])->withInput();
        }

        $excerpt = trim($validated['answer_excerpt']);
        $sourcePageChunks = $match->markingScheme->chunks()
            ->where('extraction_version', $match->marking_scheme_extraction_version)
            ->where('page', $validated['source_page'])
            ->get();
        abort_unless($sourcePageChunks->isNotEmpty(), 404);
        $sourceChunk = $sourcePageChunks->first(fn ($chunk): bool => str_contains((string) $chunk->content, $excerpt));
        if (! $sourceChunk) {
            return back()->withErrors([
                'answer_excerpt' => 'Copy a short, exact passage from the answer-key page shown above. Include the question number where possible.',
            ])->withInput();
        }

        $answer = DB::transaction(function () use ($material, $question, $match, $sourceChunk, $validated, $optionIds, $excerpt, $request): PastPaperVerifiedAnswer {
            $lockedPaper = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $lockedQuestion = $lockedPaper->pastPaperQuestions()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $lockedMatch = $this->currentMatches($lockedPaper)->firstWhere('id', $match->id);
            abort_unless($lockedMatch instanceof PastPaperMarkingSchemeMatch, 404);
            $lockedChunk = $lockedMatch->markingScheme->chunks()
                ->whereKey($sourceChunk->id)
                ->where('extraction_version', $lockedMatch->marking_scheme_extraction_version)
                ->where('page', $validated['source_page'])
                ->firstOrFail();

            abort_unless($lockedPaper->extraction_version === $lockedMatch->paper_extraction_version
                && $lockedQuestion->extraction_version === $lockedPaper->extraction_version
                && str_contains((string) $lockedChunk->content, $excerpt), 409);

            $attributes = [
                'past_paper_question_id' => $lockedQuestion->id,
                'marking_scheme_match_id' => $lockedMatch->id,
                'paper_extraction_version' => $lockedPaper->extraction_version,
                'marking_scheme_extraction_version' => $lockedMatch->marking_scheme_extraction_version,
                'marking_scheme_chunk_id' => $lockedChunk->id,
                'confirmed_by' => $request->user()->id,
                'source_page' => $lockedChunk->page,
                'option_ids' => $optionIds,
                'answer_excerpt' => $excerpt,
            ];
            $latest = PastPaperVerifiedAnswer::query()
                ->where('past_paper_question_id', $lockedQuestion->id)
                ->orderByDesc('confirmation_version')
                ->first();

            if ($latest
                && (int) $latest->marking_scheme_match_id === (int) $lockedMatch->id
                && (int) $latest->marking_scheme_chunk_id === (int) $attributes['marking_scheme_chunk_id']
                && (int) $latest->confirmed_by === (int) $attributes['confirmed_by']
                && (int) $latest->source_page === (int) $attributes['source_page']
                && $latest->option_ids === $attributes['option_ids']
                && $latest->answer_excerpt === $attributes['answer_excerpt']) {
                return $latest;
            }

            $attributes['confirmation_version'] = ($latest?->confirmation_version ?? 0) + 1;

            return PastPaperVerifiedAnswer::query()->create($attributes);
        });

        return redirect()->route('materials.show', $material)
            ->with('status', 'You checked the answer against both original pages. Future practice can use it. Earlier results are unchanged.')
            ->withFragment('past-paper-question-'.$question->id);
    }

    private function assertEligibleQuestion(Material $material, PastPaperQuestion $question): void
    {
        abort_unless($material->role === 'past_paper'
            && $material->status === 'ready'
            && $question->material_id === $material->id
            && $question->extraction_version === $material->extraction_version
            && in_array($question->review_status, PastPaperQuestion::PRACTICE_READY_STATUSES, true), 404);
        abort_unless(in_array($question->question_type, ['single_choice', 'multiple_select', 'true_false'], true)
            && count($question->options ?? []) >= 2, 409);
    }

    private function currentMatches(Material $material)
    {
        return $material->confirmedMarkingSchemes()
            ->with('markingScheme')
            ->where('paper_extraction_version', $material->extraction_version)
            ->whereHas('markingScheme', fn ($query) => $query
                ->where('learning_space_id', $material->learning_space_id)
                ->where('role', 'marking_scheme')
                ->where('status', 'ready')
                ->whereColumn('materials.extraction_version', 'past_paper_marking_scheme_matches.marking_scheme_extraction_version'))
            ->latest()
            ->get();
    }
}
