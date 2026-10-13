<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperAttempt;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperQuestion;
use App\Models\User;
use App\Services\Learning\PastPaperQuestionIndexer;
use App\Services\Learning\PastPaperQuestionParser;
use App\Services\Learning\PastPaperWrittenFeedbackException;
use App\Services\Learning\PastPaperWrittenFeedbackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PastPaperPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_submit_objective_response_and_only_then_see_detected_source_answer(): void
    {
        [$user, $material, $question] = $this->candidate();

        $this->actingAs($user)->get(route('past-paper-questions.practice', [$material, $question]))
            ->assertOk()
            ->assertSee('data-read-aloud-target="past-paper-question-content"', false)
            ->assertSee('Submit answer')
            ->assertSee('Which option is correct?')
            ->assertSeeInOrder(['Which option is correct?', 'First choice', 'From your paper:', 'Submit answer'])
            ->assertDontSee('A is the answer');

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'A'])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('correct', $attempt->outcome);
        $this->assertSame('extracted_source_response', $attempt->marking_basis);
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertOk()
            ->assertSee('data-read-aloud-controls', false)
            ->assertSee('Your answer matches the answer text found in the paper.')
            ->assertSee('A is the answer')
            ->assertSee('Check it with a linked answer key before using it to score future practice.');
    }

    public function test_objective_response_mismatch_is_reported_without_claiming_an_official_mark(): void
    {
        [$user, $material, $question] = $this->candidate();

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'B'])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('incorrect', $attempt->outcome);
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertOk()
            ->assertSee('does not match the answer text found in the paper')
            ->assertSee('The text may not match your original paper exactly.');
    }

    public function test_structured_response_is_saved_but_not_scored_or_given_a_fabricated_answer(): void
    {
        [$user, $material, $question] = $this->candidate([
            'question_type' => 'structured_response',
            'prompt' => 'Explain the principle in your own words.',
            'options' => null,
            'expected_answer' => 'A description that sounds explanatory.',
        ]);

        $this->actingAs($user)->get(route('past-paper-questions.practice', [$material, $question]))
            ->assertOk()
            ->assertDontSee('A description that sounds explanatory.');

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'My explanation'])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('submitted_ungraded', $attempt->outcome);
        $this->assertSame('none', $attempt->marking_basis);
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertOk()
            ->assertSee('No score was assigned.')
            ->assertSee('A description that sounds explanatory.')
            ->assertSee('written feedback is not available right now');
    }

    public function test_submitted_written_answer_gets_source_linked_explanation_without_a_score(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/PastPaperFeedback/work-formula-fill-gap.json')), true, flags: JSON_THROW_ON_ERROR);
        [$user, $material, $question] = $this->candidate([
            'question_type' => 'fill_gap',
            'prompt' => $fixture['question'],
            'options' => null,
            'expected_answer' => 'W = Fd',
            'answer_source' => 'source_expected_response',
            'source_text' => $fixture['question'],
        ]);
        $longSourceText = $fixture['source_text'].' '.str_repeat('Additional source text. ', 100);
        $question->sourceChunk->update(['content' => $longSourceText]);
        $fake = new class($fixture, $question->sourceChunk->id) implements PastPaperWrittenFeedbackGenerator
        {
            public array $received = [];

            public function __construct(private array $fixture, private int $sourceId) {}

            public function generate(string $question, string $questionType, string $learnerResponse, array $evidence): array
            {
                $this->received = compact('question', 'questionType', 'learnerResponse', 'evidence');

                return [
                    ...$this->fixture['expected_feedback'],
                    'source_chunk_ids' => [$this->sourceId],
                ];
            }
        };
        $this->app->instance(PastPaperWrittenFeedbackGenerator::class, $fake);

        $this->actingAs($user)->get(route('past-paper-questions.practice', [$material, $question]))
            ->assertOk()
            ->assertDontSee('Understand the answer')
            ->assertDontSee('You used the formula stated in the paper.');

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => $fixture['learner_response']])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('submitted_ungraded', $attempt->outcome);
        $this->assertSame('none', $attempt->marking_basis);
        $this->assertSame('ready', $attempt->written_feedback_status);
        $this->assertSame([$question->sourceChunk->id], $attempt->written_feedback_source_chunk_ids);
        $this->assertSame($fixture['learner_response'], $fake->received['learnerResponse']);
        $this->assertSame('fill_gap', $fake->received['questionType']);
        $this->assertSame((int) $question->sourceChunk->id, $fake->received['evidence'][0]['id']);

        $result = $this->get(route('past-paper-attempts.show', $attempt));
        $result->assertOk()
            ->assertViewHas('writtenFeedbackEvidence', fn ($items): bool => $items->count() === 1
                && mb_strlen($items[0]['excerpt']) === 900
                && $items[0]['excerpt'] === mb_substr($longSourceText, 0, 900)
                && $items[0]['page'] === 12)
            ->assertSee('No score was assigned.')
            ->assertSee('Understand the answer')
            ->assertSee('<p data-render-math>You used the formula stated in the paper.</p>', false)
            ->assertSee('<li data-render-math><strong>Use the source formula</strong><br>The paper shows \\(W = Fd\\).</li>', false)
            ->assertSee('<li data-render-math><strong>Match the symbols</strong><br>The source defines \\(F\\) as force and \\(d\\) as distance.</li>', false)
            ->assertSee('The paper shows \\(W = Fd\\).')
            ->assertSee('From your uploaded paper')
            ->assertSee($material->title.' · page 12 · question 1')
            ->assertSee('Work is calculated with \\(W = Fd\\), where \\(F\\) is force and \\(d\\) is distance.')
            ->assertSee('This is practice feedback.')
            ->assertDontSee('written_feedback_status');
    }

    public function test_written_feedback_with_foreign_source_citation_is_not_shown(): void
    {
        [$user, $material, $question] = $this->candidate([
            'question_type' => 'structured_response', 'options' => null,
        ]);
        $this->app->instance(PastPaperWrittenFeedbackGenerator::class, new class implements PastPaperWrittenFeedbackGenerator
        {
            public function generate(string $question, string $questionType, string $learnerResponse, array $evidence): array
            {
                return [
                    'status' => 'ready', 'feedback' => 'Unsupported response feedback.',
                    'steps' => [['title' => 'Step', 'detail' => 'This should not be stored.']],
                    'source_chunk_ids' => [999999],
                ];
            }
        });

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'My answer'])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('unavailable', $attempt->written_feedback_status);
        $this->assertNull($attempt->written_feedback);
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertOk()
            ->assertSee('written feedback is not available right now')
            ->assertDontSee('Unsupported response feedback.');
    }

    public function test_provider_failure_keeps_the_written_response_and_displays_clear_unscored_state(): void
    {
        [$user, $material, $question] = $this->candidate([
            'question_type' => 'structured_response', 'options' => null,
        ]);
        $this->app->instance(PastPaperWrittenFeedbackGenerator::class, new class implements PastPaperWrittenFeedbackGenerator
        {
            public function generate(string $question, string $questionType, string $learnerResponse, array $evidence): array
            {
                throw new PastPaperWrittenFeedbackException('unhelpful internal message');
            }
        });

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'My answer'])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame(['value' => 'My answer'], $attempt->response);
        $this->assertSame('submitted_ungraded', $attempt->outcome);
        $this->assertSame('unavailable', $attempt->written_feedback_status);
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertOk()
            ->assertSee('Your response is saved, but written feedback is not available right now.')
            ->assertDontSee('unhelpful internal message');
    }

    public function test_uncertain_candidate_cannot_be_submitted(): void
    {
        [$user, $material, $question] = $this->candidate(['review_status' => 'needs_review']);

        $this->actingAs($user)->get(route('past-paper-questions.practice', [$material, $question]))
            ->assertNotFound();

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'A'])
            ->assertStatus(409);
        $this->assertSame(0, PastPaperAttempt::query()->count());
    }

    public function test_owner_can_correct_and_include_uncertain_candidate_without_preserving_an_unverified_key(): void
    {
        [$user, $material, $question] = $this->candidate([
            'review_status' => 'needs_review',
            'review_reason' => 'Question structure could not be identified confidently.',
        ]);
        $originalSourceText = $question->source_text;
        $originalPage = $question->page;
        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('past-paper-question-list', false)
            ->assertSee('past-paper-question-item', false)
            ->assertSee('Check or correct this question')
            ->assertSee('The answer key will not be used for scoring.');

        $this->actingAs($user)->post(route('materials.past-paper-questions.review', [$material, $question]), [
            'prompt' => 'Which choice shows the corrected question?',
            'question_type' => 'single_choice',
            'options' => ['A' => 'First corrected choice', 'B' => 'Second corrected choice'],
        ])->assertSessionHasNoErrors();

        $question->refresh();
        $this->assertSame('Which choice shows the corrected question?', $question->prompt);
        $this->assertSame('owner_checked', $question->review_status);
        $this->assertSame($originalSourceText, $question->source_text);
        $this->assertSame($originalPage, $question->page);
        $this->assertNull($question->expected_answer);
        $this->assertSame('not_detected', $question->answer_source);
        $this->assertSame([
            ['id' => 'A', 'text' => 'First corrected choice'],
            ['id' => 'B', 'text' => 'Second corrected choice'],
        ], $question->options);

        $this->actingAs($user)->get(route('past-paper-questions.practice', [$material, $question]))
            ->assertOk()
            ->assertSee('Which choice shows the corrected question?');
        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'A'])
            ->assertRedirect();
        $this->assertSame('submitted_ungraded', PastPaperAttempt::query()->firstOrFail()->outcome);
        $this->assertSame('none', PastPaperAttempt::query()->firstOrFail()->marking_basis);

        $indexer = new PastPaperQuestionIndexer(new PastPaperQuestionParser);
        $indexer->index($material->fresh());
        $this->assertSame('Which choice shows the corrected question?', $question->fresh()->prompt);
        $this->assertSame($originalSourceText, $question->fresh()->source_text);
    }

    public function test_only_material_owner_can_review_an_uncertain_candidate(): void
    {
        [$user, $material, $question] = $this->candidate(['review_status' => 'needs_review']);
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('materials.past-paper-questions.review', [$material, $question]), [
            'prompt' => 'A corrected question that is long enough.',
            'question_type' => 'structured_response',
        ])->assertForbidden();
        $this->assertSame('needs_review', $question->fresh()->review_status);
    }

    public function test_malformed_objective_response_is_rejected_and_attempt_not_saved(): void
    {
        [$user, $material, $question] = $this->candidate();

        $this->actingAs($user)->from(route('past-paper-questions.practice', [$material, $question]))
            ->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'Z'])
            ->assertSessionHasErrors('value');
        $this->assertSame(0, PastPaperAttempt::query()->count());
    }

    public function test_multi_select_key_requires_an_unambiguous_label_set(): void
    {
        [$user, $material, $question] = $this->candidate([
            'question_type' => 'multiple_select',
            'options' => [
                ['id' => 'A', 'text' => 'First choice'],
                ['id' => 'B', 'text' => 'Second choice'],
                ['id' => 'C', 'text' => 'Third choice'],
            ],
            'expected_answer' => 'A and C',
        ]);

        $this->actingAs($user)->post(route('past-paper-questions.submit', [$material, $question]), ['values' => ['C', 'A']])
            ->assertRedirect();

        $attempt = PastPaperAttempt::query()->firstOrFail();
        $this->assertSame('correct', $attempt->outcome);
        $this->assertSame('extracted_source_response', $attempt->marking_basis);
    }

    public function test_owner_can_submit_a_paper_set_once_and_review_each_response_with_its_source(): void
    {
        [$user, $material, $first] = $this->candidate();
        $second = $this->addCandidate($material, [
            'question_number' => '2',
            'prompt' => 'Explain the idea in your own words.',
            'question_type' => 'structured_response',
            'options' => null,
            'expected_answer' => 'A source response that must not be scored automatically.',
        ]);
        $excluded = $this->addCandidate($material, [
            'question_number' => '3',
            'prompt' => 'Which visual detail is shown in the figure?',
            'review_status' => 'needs_review',
            'review_reason' => 'This candidate requires a visual check.',
        ]);

        $start = $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 30]);
        $run = PastPaperPracticeRun::query()->firstOrFail();
        $this->assertSame([$first->id, $second->id], $run->question_ids);
        $this->assertSame(30, $run->duration_minutes);
        $this->assertTrue($run->expires_at->equalTo($run->started_at->copy()->addMinutes(30)));
        $start->assertRedirect(route('past-paper-practice.show', [$material, $run]));

        $this->get(route('past-paper-practice.show', [$material, $run]))
            ->assertOk()
            ->assertSee('data-read-aloud-target="paper-exam-instructions"', false)
            ->assertSee('data-read-aloud-target="paper-question-content-'.$first->id.'"', false)
            ->assertSee('data-read-aloud-url="'.route('read-aloud').'"', false)
            ->assertDontSee('AI-generated speech')
            ->assertDontSee('AI voice')
            ->assertDontSee('The text you select is sent')
            ->assertSee('Listen to this')
            ->assertSee('Exam practice')
            ->assertSee('Finish and submit')
            ->assertSee('Your answers are saved as you practise. Submit when you are ready.')
            ->assertSee('Which option is correct?')
            ->assertSee('Explain the idea in your own words.')
            ->assertDontSee('A is the answer')
            ->assertDontSee($excluded->prompt);

        $response = $this->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [
                $first->id => ['value' => 'A'],
                $second->id => ['value' => 'My explanation'],
                $excluded->id => ['value' => 'This must not be accepted'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, $run->attempts()->count());
        $this->assertNull($run->fresh()->submitted_at);

        $this->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [
                $first->id => ['value' => 'A'],
                $second->id => ['value' => 'My explanation'],
            ],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $this->assertSame(2, $run->attempts()->count());
        $outcomes = $run->attempts()->pluck('outcome', 'past_paper_question_id');
        $this->assertSame('correct', $outcomes[$first->id]);
        $this->assertSame('submitted_ungraded', $outcomes[$second->id]);
        $this->get(route('past-paper-practice.results', $run))
            ->assertOk()
            ->assertSee('data-read-aloud-target="paper-result-question-'.$first->id.'"', false)
            ->assertSee('Your paper practice')
            ->assertSee('A is the answer')
            ->assertSee('Each result shows whether it used a key you checked')
            ->assertSee('Page 12');

        $this->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [$first->id => ['value' => 'B']],
        ])->assertRedirect(route('past-paper-practice.results', $run));
        $this->assertSame(2, $run->attempts()->count());
    }

    public function test_paper_practice_can_be_submitted_with_every_question_unanswered(): void
    {
        [$user, $material, $question] = $this->candidate(['question_type' => 'structured_response', 'options' => null]);
        $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 60]);
        $run = PastPaperPracticeRun::query()->firstOrFail();

        $this->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [$question->id => ['value' => '']],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $this->assertNotNull($run->fresh()->submitted_at);
        $this->assertSame(0, $run->attempts()->count());
        $this->get(route('past-paper-practice.results', $run))->assertSee('1 unanswered');
    }

    public function test_late_paper_payload_is_finalized_without_scoring_any_answer(): void
    {
        [$user, $material, $question] = $this->candidate();
        $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 30]);
        $run = PastPaperPracticeRun::query()->firstOrFail();

        $this->travel(31)->minutes();
        $this->actingAs($user)->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [$question->id => ['value' => 'A']],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $run->refresh();
        $this->assertNotNull($run->submitted_at);
        $this->assertSame(0, $run->attempts()->count());
        $this->get(route('past-paper-practice.results', $run))
            ->assertOk()
            ->assertSee('Time expired before your answers reached us. No answers were scored.')
            ->assertSee('This question was unanswered when time expired.')
            ->assertSee('1 unanswered')
            ->assertDontSee('Your response');
    }

    public function test_opening_an_expired_paper_run_finalizes_it_without_answers(): void
    {
        [$user, $material] = $this->candidate();
        $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 30]);
        $run = PastPaperPracticeRun::query()->firstOrFail();
        $this->travel(31)->minutes();

        $this->actingAs($user)->get(route('past-paper-practice.show', [$material, $run]))
            ->assertRedirect(route('past-paper-practice.results', $run));
        $this->assertNotNull($run->fresh()->submitted_at);
        $this->assertSame(0, $run->attempts()->count());
    }

    public function test_paper_practice_duration_is_limited_to_available_choices(): void
    {
        [$user, $material] = $this->candidate();

        $this->actingAs($user)
            ->from(route('materials.show', $material))
            ->post(route('past-paper-practice.start', $material), ['duration_minutes' => 12])
            ->assertSessionHasErrors('duration_minutes');

        $this->assertSame(0, PastPaperPracticeRun::query()->count());
    }

    public function test_paper_practice_preserves_multi_select_true_false_and_fill_gap_controls(): void
    {
        [$user, $material, $first] = $this->candidate();
        $multi = $this->addCandidate($material, [
            'question_number' => '2',
            'prompt' => 'Select all correct statements.',
            'question_type' => 'multiple_select',
            'options' => [['id' => 'A', 'text' => 'First'], ['id' => 'B', 'text' => 'Second'], ['id' => 'C', 'text' => 'Third']],
            'expected_answer' => 'A and C',
            'answer_source' => 'source_expected_response',
        ]);
        $trueFalse = $this->addCandidate($material, [
            'question_number' => '3',
            'prompt' => 'The statement is true or false?',
            'question_type' => 'true_false',
            'options' => [['id' => 'A', 'text' => 'True'], ['id' => 'B', 'text' => 'False']],
            'expected_answer' => 'A',
            'answer_source' => 'source_expected_response',
        ]);
        $fillGap = $this->addCandidate($material, [
            'question_number' => '4',
            'prompt' => 'Fill in the gap: energy is measured in _____.',
            'question_type' => 'fill_gap',
            'options' => null,
            'expected_answer' => 'joules',
            'answer_source' => 'source_expected_response',
        ]);

        $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 30]);
        $run = PastPaperPracticeRun::query()->firstOrFail();
        $this->get(route('past-paper-practice.show', [$material, $run]))
            ->assertOk()
            ->assertSee('type="checkbox"', false)
            ->assertSee('type="radio"', false)
            ->assertSee('Fill in the gap: energy is measured in', false);

        $this->post(route('past-paper-practice.submit', [$material, $run]), [
            'answers' => [
                $first->id => ['value' => 'A'],
                $multi->id => ['values' => ['C', 'A']],
                $trueFalse->id => ['value' => 'B'],
                $fillGap->id => ['value' => 'joules'],
            ],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $outcomes = $run->attempts()->pluck('outcome', 'past_paper_question_id');
        $this->assertSame('correct', $outcomes[$first->id]);
        $this->assertSame('correct', $outcomes[$multi->id]);
        $this->assertSame('incorrect', $outcomes[$trueFalse->id]);
        $this->assertSame('submitted_ungraded', $outcomes[$fillGap->id]);
    }

    public function test_paper_practice_is_owner_scoped_and_rejects_a_run_after_reextraction(): void
    {
        [$user, $material] = $this->candidate();
        $this->actingAs($user)->post(route('past-paper-practice.start', $material), ['duration_minutes' => 30]);
        $run = PastPaperPracticeRun::query()->firstOrFail();

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('past-paper-practice.show', [$material, $run]))->assertForbidden();

        $material->update(['extraction_version' => 2]);
        $this->actingAs($user)->get(route('past-paper-practice.show', [$material, $run]))->assertStatus(409);
    }

    public function test_non_owner_cannot_view_submit_or_read_attempt(): void
    {
        [$user, $material, $question] = $this->candidate();
        $attempt = PastPaperAttempt::query()->create([
            'past_paper_question_id' => $question->id,
            'user_id' => $user->id,
            'response' => ['value' => 'A'],
            'outcome' => 'correct',
            'marking_basis' => 'extracted_source_response',
            'written_feedback_status' => 'ready',
            'written_feedback' => 'Private practice feedback.',
            'written_feedback_steps' => [['title' => 'Step', 'detail' => 'Private explanation.']],
            'written_feedback_source_chunk_ids' => [$question->material_chunk_id],
            'submitted_at' => now(),
        ]);
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('past-paper-questions.practice', [$material, $question]))->assertForbidden();
        $this->post(route('past-paper-questions.submit', [$material, $question]), ['value' => 'A'])->assertForbidden();
        $this->get(route('past-paper-attempts.show', $attempt))
            ->assertForbidden()
            ->assertDontSee('Private practice feedback.');
    }

    private function candidate(array $overrides = []): array
    {
        $user = User::factory()->create();
        $material = Material::factory()->for(LearningSpace::factory()->for($user))->create([
            'role' => 'past_paper', 'status' => 'ready', 'extraction_version' => 1,
        ]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Question 1. Which option is correct? A is the answer. Page text only revealed after submission.',
            'page' => 12,
            'locator' => ['page' => 12, 'extractor' => 'pdftotext', 'confidence' => .95],
            'relevance_status' => 'related',
        ]);
        $question = PastPaperQuestion::query()->create(array_merge([
            'material_id' => $material->id,
            'material_chunk_id' => $chunk->id,
            'extraction_version' => 1,
            'page' => 12,
            'question_number' => '1',
            'prompt' => 'Which option is correct?',
            'question_type' => 'single_choice',
            'options' => [['id' => 'A', 'text' => 'First choice'], ['id' => 'B', 'text' => 'Second choice']],
            'source_text' => 'Which option is correct? A. First choice B. Second choice',
            'expected_answer' => 'A',
            'answer_source' => 'source_expected_response',
            'review_status' => 'ready',
            'review_reason' => null,
            'confidence' => .95,
            'parser_version' => 'page-question-parser-v1',
        ], $overrides));

        return [$user, $material, $question];
    }

    private function addCandidate(Material $material, array $overrides = []): PastPaperQuestion
    {
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Another question from the same source paper.',
            'page' => 13,
            'locator' => ['page' => 13, 'extractor' => 'pdftotext', 'confidence' => .95],
            'relevance_status' => 'related',
        ]);

        return PastPaperQuestion::query()->create(array_merge([
            'material_id' => $material->id,
            'material_chunk_id' => $chunk->id,
            'extraction_version' => 1,
            'page' => 13,
            'question_number' => '2',
            'prompt' => 'Another question in this paper?',
            'question_type' => 'single_choice',
            'options' => [['id' => 'A', 'text' => 'First'], ['id' => 'B', 'text' => 'Second']],
            'source_text' => 'Another question in this paper? A. First B. Second',
            'expected_answer' => null,
            'answer_source' => 'not_detected',
            'review_status' => 'ready',
            'review_reason' => null,
            'confidence' => .95,
            'parser_version' => 'page-question-parser-v1',
        ], $overrides));
    }
}
