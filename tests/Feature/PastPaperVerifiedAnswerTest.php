<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperAttempt;
use App\Models\PastPaperMarkingSchemeMatch;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperVerifiedAnswer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PastPaperVerifiedAnswerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_check_one_key_answer_against_both_original_pages_and_score_future_practice(): void
    {
        [$owner, $paper, $scheme, $match, $question] = $this->fixture();
        $schemeChunk = $scheme->chunks()->create([
            'page' => 4,
            'content' => "Answers for Section A\n1. B. Friction transfers energy into heat.",
            'locator' => ['page' => 4],
            'extraction_version' => $scheme->extraction_version,
            'relevance_status' => 'related',
        ]);

        $this->actingAs($owner)->get(route('past-paper-questions.verify-answer', [$paper, $question]))
            ->assertOk()
            ->assertSee('Original question paper, page 2')
            ->assertSee('Original answer key, page 4')
            ->assertSee(route('materials.preview', $paper), false)
            ->assertSee(route('materials.preview', $scheme), false)
            ->assertSee('Question 1: What happens to energy during friction?')
            ->assertSee('Answers for Section A');

        $this->from(route('past-paper-questions.verify-answer', [$paper, $question]))
            ->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
                'marking_scheme_match_id' => $match->id,
                'source_page' => 4,
                'answer_option_ids' => ['B'],
                'answer_excerpt' => '1. B. Friction transfers energy into heat.',
                'confirm_sources' => '1',
            ])
            ->assertRedirect(route('materials.show', $paper).'#past-paper-question-'.$question->id);

        $verifiedAnswer = PastPaperVerifiedAnswer::query()->sole();
        $this->assertSame($question->id, $verifiedAnswer->past_paper_question_id);
        $this->assertSame($match->id, $verifiedAnswer->marking_scheme_match_id);
        $this->assertSame($schemeChunk->id, $verifiedAnswer->marking_scheme_chunk_id);
        $this->assertSame(4, $verifiedAnswer->source_page);
        $this->assertSame(['B'], $verifiedAnswer->option_ids);
        $this->assertSame($owner->id, $verifiedAnswer->confirmed_by);

        $this->get(route('materials.show', $paper))
            ->assertOk()
            ->assertSee('Answer checked by you against a linked key')
            ->assertSee('Review checked answer');

        $this->post(route('past-paper-practice.start', $paper), ['duration_minutes' => 30])->assertRedirect();
        $run = PastPaperPracticeRun::query()->sole();
        $this->get(route('past-paper-practice.show', [$paper, $run]))
            ->assertOk()
            ->assertDontSee('Friction transfers energy into heat');

        $this->post(route('past-paper-practice.submit', [$paper, $run]), [
            'answers' => [$question->id => ['value' => 'B']],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $attempt = PastPaperAttempt::query()->sole();
        $this->assertSame('correct', $attempt->outcome);
        $this->assertSame('confirmed_marking_scheme', $attempt->marking_basis);
        $this->assertSame($verifiedAnswer->id, $attempt->verified_answer_id);
        $this->get(route('past-paper-practice.results', $run))
            ->assertOk()
            ->assertSee('Your answer matches the answer key you checked against the original.')
            ->assertSee('Friction transfers energy into heat.')
            ->assertSee('This is practice feedback.');

        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 4,
            'answer_option_ids' => ['A'],
            'answer_excerpt' => '1. B. Friction transfers energy into heat.',
            'confirm_sources' => '1',
        ])->assertRedirect();
        $reconfirmedAnswer = PastPaperVerifiedAnswer::query()->orderByDesc('confirmation_version')->firstOrFail();
        $this->assertNotSame($verifiedAnswer->id, $reconfirmedAnswer->id);
        $this->assertSame('B', $attempt->fresh()->verifiedAnswer->option_ids[0]);
        $this->get(route('past-paper-practice.results', $run))
            ->assertOk()
            ->assertSee('Option B')
            ->assertSee('Friction transfers energy into heat.');

        $this->post(route('past-paper-practice.start', $paper), ['duration_minutes' => 30])->assertRedirect();
        $nextRun = PastPaperPracticeRun::query()->orderByDesc('id')->firstOrFail();
        $this->post(route('past-paper-practice.submit', [$paper, $nextRun]), [
            'answers' => [$question->id => ['value' => 'A']],
        ])->assertRedirect();
        $nextAttempt = PastPaperAttempt::query()->where('practice_run_id', $nextRun->id)->sole();
        $this->assertSame('correct', $nextAttempt->outcome);
        $this->assertSame('confirmed_marking_scheme', $nextAttempt->marking_basis);
        $this->assertSame($reconfirmedAnswer->id, $nextAttempt->verified_answer_id, json_encode([
            'expected' => $reconfirmedAnswer->toArray(),
            'attempt' => $nextAttempt->toArray(),
        ]));
    }

    public function test_answer_excerpt_must_come_from_the_selected_current_key_page_and_confirmation_is_required(): void
    {
        [$owner, $paper, , $match, $question] = $this->fixture();
        $scheme = $match->markingScheme;
        $scheme->chunks()->create([
            'page' => 4,
            'content' => 'Answer 1: B',
            'locator' => ['page' => 4],
            'extraction_version' => $scheme->extraction_version,
            'relevance_status' => 'related',
        ]);
        $this->actingAs($owner);

        $this->from(route('past-paper-questions.verify-answer', [$paper, $question]))
            ->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
                'marking_scheme_match_id' => $match->id,
                'source_page' => 4,
                'answer_option_ids' => ['B'],
                'answer_excerpt' => '1. The answer is B',
                'confirm_sources' => '1',
            ])
            ->assertSessionHasErrors(['answer_excerpt']);
        $this->assertSame(0, PastPaperVerifiedAnswer::query()->count());

        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 4,
            'answer_option_ids' => ['B'],
            'answer_excerpt' => 'Answer 1: B',
        ])->assertSessionHasErrors(['confirm_sources']);

        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 5,
            'answer_option_ids' => ['B'],
            'answer_excerpt' => 'Answer 1: B',
            'confirm_sources' => '1',
        ])->assertNotFound();
        $this->assertSame(0, PastPaperVerifiedAnswer::query()->count());
    }

    public function test_answer_key_linked_to_another_paper_and_foreign_source_pages_cannot_be_used(): void
    {
        [$owner, $paper, $scheme, , $question] = $this->fixture();
        $otherPaper = $this->material($paper->learningSpace, 'Other paper', 'past_paper');
        $foreignMatch = PastPaperMarkingSchemeMatch::query()->create([
            'paper_material_id' => $otherPaper->id,
            'marking_scheme_material_id' => $scheme->id,
            'confirmed_by' => $owner->id,
            'paper_extraction_version' => $otherPaper->extraction_version,
            'marking_scheme_extraction_version' => $scheme->extraction_version,
        ]);
        $scheme->chunks()->create([
            'page' => 4,
            'content' => 'Answer 1: B',
            'locator' => ['page' => 4],
            'extraction_version' => $scheme->extraction_version,
            'relevance_status' => 'related',
        ]);

        $this->actingAs($owner)->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $foreignMatch->id,
            'source_page' => 4,
            'answer_option_ids' => ['B'],
            'answer_excerpt' => 'Answer 1: B',
            'confirm_sources' => '1',
        ])->assertNotFound();
        $this->assertSame(0, PastPaperVerifiedAnswer::query()->count());
    }

    public function test_another_learner_cannot_preview_original_sources_or_verify_an_answer(): void
    {
        [$owner, $paper, $scheme, , $question] = $this->fixture();
        $other = User::factory()->create();
        Storage::fake('local');
        $paper->update(['private_source_path' => 'private/paper.pdf', 'mime_type' => 'application/pdf']);
        Storage::disk('local')->put('private/paper.pdf', '%PDF-1.4');

        $this->actingAs($other)->get(route('past-paper-questions.verify-answer', [$paper, $question]))->assertForbidden();
        $this->get(route('materials.preview', $paper))->assertForbidden();
        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $scheme->confirmedMarkingSchemes()->value('id'),
            'source_page' => 1,
            'answer_option_ids' => ['A'],
            'answer_excerpt' => 'Answer 1: A',
            'confirm_sources' => '1',
        ])->assertForbidden();
        $this->assertSame(0, PastPaperVerifiedAnswer::query()->count());
    }

    public function test_reextracted_answer_key_no_longer_scores_from_an_old_confirmation(): void
    {
        [$owner, $paper, $scheme, $match, $question] = $this->fixture();
        $scheme->chunks()->create([
            'page' => 4,
            'content' => 'Answer 1: B',
            'locator' => ['page' => 4],
            'extraction_version' => $scheme->extraction_version,
            'relevance_status' => 'related',
        ]);
        $this->actingAs($owner)->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 4,
            'answer_option_ids' => ['B'],
            'answer_excerpt' => 'Answer 1: B',
            'confirm_sources' => '1',
        ])->assertRedirect();

        $scheme->update(['extraction_version' => 2]);
        $this->post(route('past-paper-practice.start', $paper), ['duration_minutes' => 30])->assertRedirect();
        $run = PastPaperPracticeRun::query()->sole();
        $this->post(route('past-paper-practice.submit', [$paper, $run]), [
            'answers' => [$question->id => ['value' => 'B']],
        ])->assertRedirect();

        $attempt = PastPaperAttempt::query()->sole();
        $this->assertSame('incorrect', $attempt->outcome);
        $this->assertSame('extracted_source_response', $attempt->marking_basis);
        $this->assertNull($attempt->verified_answer_id);
    }

    public function test_confirmation_history_sequence_continues_when_the_paper_is_reextracted(): void
    {
        [$owner, $paper, $scheme, $match, $question] = $this->fixture();
        $scheme->chunks()->create([
            'page' => 4,
            'content' => 'Answer 1: B',
            'locator' => ['page' => 4],
            'extraction_version' => $scheme->extraction_version,
            'relevance_status' => 'related',
        ]);
        $this->actingAs($owner);

        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 4,
            'answer_option_ids' => ['B'],
            'answer_excerpt' => 'Answer 1: B',
            'confirm_sources' => '1',
        ])->assertRedirect();

        $paper->update(['extraction_version' => 2]);
        $question->update(['extraction_version' => 2]);
        $match->update(['paper_extraction_version' => 2]);

        $this->post(route('past-paper-questions.verify-answer.store', [$paper, $question]), [
            'marking_scheme_match_id' => $match->id,
            'source_page' => 4,
            'answer_option_ids' => ['A'],
            'answer_excerpt' => 'Answer 1: B',
            'confirm_sources' => '1',
        ])->assertRedirect();

        $this->assertSame([1, 2], PastPaperVerifiedAnswer::query()
            ->where('past_paper_question_id', $question->id)
            ->orderBy('confirmation_version')
            ->pluck('confirmation_version')
            ->all());
    }

    public function test_preview_returns_the_private_original_inline_only_for_supported_materials(): void
    {
        [, $paper] = $this->fixture();
        Storage::fake('local');
        $paper->update(['private_source_path' => 'private/paper.pdf', 'mime_type' => 'application/pdf']);
        Storage::disk('local')->put('private/paper.pdf', '%PDF-1.4');

        $this->actingAs($paper->learningSpace->user)->get(route('materials.preview', $paper))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $paper->update(['mime_type' => 'text/html']);
        $this->get(route('materials.preview', $paper))->assertStatus(415);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $space = LearningSpace::factory()->for($owner)->create();
        $paper = $this->material($space, 'Physics paper 2025', 'past_paper');
        $scheme = $this->material($space, 'Physics answer key 2025', 'marking_scheme');
        $paper->chunks()->create([
            'page' => 2,
            'content' => 'Question 1: What happens to energy during friction? A. Energy is destroyed. B. Energy moves into heat.',
            'locator' => ['page' => 2],
            'extraction_version' => $paper->extraction_version,
            'relevance_status' => 'related',
        ]);
        $question = $paper->pastPaperQuestions()->create([
            'material_chunk_id' => $paper->chunks()->value('id'),
            'extraction_version' => $paper->extraction_version,
            'page' => 2,
            'question_number' => '1',
            'prompt' => 'Question 1: What happens to energy during friction?',
            'question_type' => 'single_choice',
            'options' => [
                ['id' => 'A', 'text' => 'Energy is destroyed.'],
                ['id' => 'B', 'text' => 'Energy moves into heat.'],
            ],
            'source_text' => 'Question 1: What happens to energy during friction? A. Energy is destroyed. B. Energy moves into heat.',
            'expected_answer' => 'A',
            'answer_source' => 'source_expected_response',
            'review_status' => 'ready',
            'confidence' => 0.99,
            'parser_version' => 'test-parser-v1',
        ]);
        $match = PastPaperMarkingSchemeMatch::query()->create([
            'paper_material_id' => $paper->id,
            'marking_scheme_material_id' => $scheme->id,
            'confirmed_by' => $owner->id,
            'paper_extraction_version' => $paper->extraction_version,
            'marking_scheme_extraction_version' => $scheme->extraction_version,
        ]);

        return [$owner, $paper, $scheme, $match, $question];
    }

    private function material(LearningSpace $space, string $title, string $role): Material
    {
        return $space->materials()->create([
            'title' => $title,
            'role' => $role,
            'private_source_path' => 'private/'.str_replace(' ', '-', strtolower($title)).'.pdf',
            'original_filename' => str_replace(' ', '-', strtolower($title)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => 'ready',
            'extraction_version' => 1,
        ]);
    }
}
