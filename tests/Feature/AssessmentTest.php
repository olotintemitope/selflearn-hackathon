<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\MaterialChunk;
use App\Models\User;
use App\Services\Learning\AnswerMarker;
use App\Services\Learning\AnswerMarkingException;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\OpenEndedQuestionGenerator;
use App\Services\Learning\QuizGenerationException;
use App\Services\Learning\QuizGenerator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_exam_mode_is_source_only_timed_and_releases_feedback_after_submission(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $sourceTitle = $concept->sources()->firstOrFail()->material->title;

        $this->actingAs($user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Guided practice')
            ->assertSee('Start exam');

        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]), ['mode' => 'exam'])
            ->assertRedirect();
        $assessment = Assessment::with('questions')->firstOrFail();
        $this->assertSame('exam', $assessment->mode);
        $this->assertSame(1200, $assessment->duration_seconds);
        $this->assertSame('source_supported', $assessment->questions->firstWhere('question_type', 'open_ended')->knowledge_basis);

        $this->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Exam mode')
            ->assertSee('Time remaining: 20:00')
            ->assertSee('data-read-aloud-target="assessment-instructions"', false)
            ->assertSee('data-read-aloud-target="assessment-question-'.$assessment->questions->firstWhere('question_type', 'multiple_choice')->id.'"', false)
            ->assertSee('data-read-aloud-url="'.route('read-aloud').'"', false)
            ->assertSee('Choose Listen to hear this text in an AI voice. The text you select is sent to create the audio.')
            ->assertSee('Listen to this')
            ->assertSee('Submit exam')
            ->assertDontSee($sourceTitle)
            ->assertDontSee('Beyond this material:')
            ->assertDontSee('How to work it out')
            ->assertDontSee('Correct answer');

        $this->travel(21)->minutes();
        $this->post(route('assessments.submit', $assessment), ['attempt_uuid' => (string) str()->uuid()])
            ->assertRedirect();
        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertSame(0, $attempt->correct_count);
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_attempt_id' => $attempt->id,
            'assessment_question_id' => $written->id,
            'written_response' => '',
            'marking_status' => 'not_applicable',
        ]);
        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('Exam submitted')
            ->assertSee('data-read-aloud-controls', false)
            ->assertSee('How to work it out')
            ->assertSee('Want to learn from this result?')
            ->assertSee('How to work it out')
            ->assertDontSee('Your next lesson');
    }

    public function test_exam_generation_failure_explains_the_next_action_without_provider_jargon(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->app->instance(QuizGenerator::class, new class implements QuizGenerator
        {
            public function generate(string $conceptName, array $evidence, int $count = 3, string $mode = 'guided'): array
            {
                throw new QuizGenerationException('The quiz provider returned an incomplete question.');
            }
        });

        $this->actingAs($user)->from(route('study.show', $space))
            ->post(route('assessments.store', [$space, $concept]), ['mode' => 'exam'])
            ->assertRedirect(route('study.show', $space))
            ->assertSessionHasErrors(['practice' => 'I could not build a fair exam from clear evidence in your materials. Review the source material, try guided practice, or add a clearer page, then start a new exam.']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_expired_exam_timer_stays_at_zero_instead_of_gaining_time(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $chunk = $concept->sources()->firstOrFail();
        $assessment = $space->assessments()->create([
            'concept_id' => $concept->id,
            'prompt_version' => 'grounded-mixed-practice-v8',
            'mode' => 'exam',
            'started_at' => now()->subSeconds(1300),
            'duration_seconds' => 1200,
        ]);
        $assessment->questions()->create([
            'concept_id' => $concept->id,
            'knowledge_basis' => 'source_supported',
            'prompt' => 'What do the notes say?',
            'options' => [
                ['id' => 'A', 'text' => 'Plants use light energy.'],
                ['id' => 'B', 'text' => 'Plants use moonlight.'],
            ],
            'correct_option_id' => 'A',
            'explanation' => 'The notes say plants use light energy.',
            'source_refs' => [$chunk->id],
        ]);

        $this->actingAs($user)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Time remaining: 00:00')
            ->assertSee('data-seconds="0"', false);
    }

    public function test_late_exam_payload_is_finalized_with_every_answer_unanswered(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]), ['mode' => 'exam']);
        $assessment = Assessment::with('questions')->firstOrFail();
        $this->actingAs($user)->get(route('assessments.show', $assessment))->assertOk();

        $this->travel(21)->minutes();
        $answers = $assessment->questions->where('question_type', 'multiple_choice')
            ->mapWithKeys(fn ($question) => [$question->id => $question->correct_option_id])->all();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');

        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => (string) str()->uuid(),
            'answers' => $answers,
            'responses' => [$written->id => 'This late response must not be marked.'],
        ])->assertRedirect();

        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertSame(0, $attempt->correct_count);
        $this->assertSame(3, $attempt->question_count);
        $this->assertDatabaseCount('assessment_answers', 4);
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_attempt_id' => $attempt->id,
            'assessment_question_id' => $written->id,
            'written_response' => '',
            'marking_status' => 'not_applicable',
        ]);
        $multipleChoiceIds = $assessment->questions->where('question_type', 'multiple_choice')->modelKeys();
        $this->assertSame(0, $attempt->answers()->where('correct', true)->count());
        $this->assertSame(3, $attempt->answers()->whereIn('assessment_question_id', $multipleChoiceIds)->where('selected_option_id', '')->count());
        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('Time expired before your answers reached us. No answers were scored.')
            ->assertSee('The questions below are shown as unanswered.')
            ->assertSee('Unanswered')
            ->assertDontSee('This late response must not be marked.');
    }

    public function test_exam_payload_at_the_exact_deadline_is_not_scored(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]), ['mode' => 'exam']);
        $assessment = Assessment::with('questions')->firstOrFail();
        $this->actingAs($user)->get(route('assessments.show', $assessment))->assertOk();
        $assessment->refresh();
        $this->travelTo($assessment->started_at->copy()->addSeconds($assessment->duration_seconds));

        $answers = $assessment->questions->where('question_type', 'multiple_choice')
            ->mapWithKeys(fn ($question) => [$question->id => $question->correct_option_id])->all();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => (string) str()->uuid(),
            'answers' => $answers,
            'responses' => [$written->id => 'This answer arrived exactly at the deadline.'],
        ])->assertRedirect();

        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertSame(0, $attempt->correct_count);
        $this->assertSame(0, $attempt->answers()->where('correct', true)->count());
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_attempt_id' => $attempt->id,
            'assessment_question_id' => $written->id,
            'written_response' => '',
            'marking_status' => 'not_applicable',
        ]);
        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('Time expired before your answers reached us. No answers were scored.');
    }

    public function test_assessment_submit_rechecks_attempt_uuid_after_locking_for_a_competing_request(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]));
        $assessment = Assessment::with('questions')->firstOrFail();
        $uuid = (string) str()->uuid();
        $competingAttempt = null;
        $inserted = false;
        $marker = new class implements AnswerMarker
        {
            public int $calls = 0;

            public function mark(string $question, string $knowledgeBasis, array $rubric, string $learnerResponse, array $evidence): array
            {
                $this->calls++;

                return ['status' => 'needs_review', 'points_awarded' => null, 'feedback' => 'Review needed.', 'criteria_results' => []];
            }
        };
        $this->app->instance(AnswerMarker::class, $marker);

        DB::listen(function (QueryExecuted $query) use ($assessment, $user, $uuid, &$competingAttempt, &$inserted): void {
            if ($inserted || ! str_contains($query->sql, 'client_attempt_uuid')) {
                return;
            }

            $inserted = true;
            $competingAttempt = $assessment->attempts()->create([
                'user_id' => $user->id,
                'client_attempt_uuid' => $uuid,
                'correct_count' => 1,
                'question_count' => 3,
                'submitted_at' => now(),
            ]);
            foreach ($assessment->questions as $question) {
                $competingAttempt->answers()->create([
                    'assessment_question_id' => $question->id,
                    'selected_option_id' => $question->question_type === 'multiple_choice' ? 'A' : '',
                    'correct' => $question->question_type === 'multiple_choice' && $question->id === $assessment->questions->first()->id,
                    'written_response' => $question->question_type === 'open_ended' ? 'Already marked by the first request.' : null,
                    'marking_status' => $question->question_type === 'open_ended' ? 'marked' : 'not_applicable',
                    'points_awarded' => $question->question_type === 'open_ended' ? 2 : null,
                    'max_points' => $question->max_points,
                ]);
            }
        });

        $answers = $assessment->questions->where('question_type', 'multiple_choice')
            ->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => $uuid,
            'answers' => $answers,
            'responses' => [$written->id => 'Duplicate response must not replace the first result.'],
        ])->assertRedirect(route('assessments.show', ['assessment' => $assessment, 'attempt' => $competingAttempt->id]));

        $this->assertTrue($inserted);
        $this->assertSame(1, $assessment->attempts()->where('client_attempt_uuid', $uuid)->count());
        $this->assertSame(2, $competingAttempt->answers()->where('assessment_question_id', $written->id)->value('points_awarded'));
        $this->assertSame(0, $marker->calls);
    }

    public function test_legacy_clock_hand_question_is_shown_as_supplementary_when_ocr_only_has_confirmed_times(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $chunk = $concept->sources()->firstOrFail();
        $chunk->update(['content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 1 shows 03:00.']);
        $assessment = $space->assessments()->create([
            'concept_id' => $concept->id,
            'prompt_version' => 'grounded-mixed-practice-v8',
            'mode' => 'guided',
        ]);
        $assessment->questions()->create([
            'concept_id' => $concept->id,
            'prompt' => 'The minute hand points to 6. What time is shown?',
            'knowledge_basis' => 'source_supported',
            'options' => [
                ['id' => 'A', 'text' => '3:30'], ['id' => 'B', 'text' => '6:30'],
                ['id' => 'C', 'text' => '6:00'], ['id' => 'D', 'text' => '3:00'],
            ],
            'correct_option_id' => 'A',
            'explanation' => 'The minute hand at 6 means half past.',
            'source_refs' => [$chunk->id],
        ]);

        $this->actingAs($user)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Beyond this material:')
            ->assertSee('This question uses standard subject knowledge');

        $this->assertSame('source_supported', $assessment->questions()->first()->knowledge_basis);
    }

    public function test_legacy_exam_with_unsupported_clock_hand_questions_is_blocked_before_its_timer_starts(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $chunk = $concept->sources()->firstOrFail();
        $chunk->update(['content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 1 shows 03:00.']);
        $assessment = $space->assessments()->create([
            'concept_id' => $concept->id,
            'prompt_version' => 'grounded-mixed-practice-v8',
            'mode' => 'exam',
            'duration_seconds' => 1200,
        ]);
        $assessment->questions()->create([
            'concept_id' => $concept->id,
            'prompt' => 'The minute hand points to 6. What time is shown?',
            'knowledge_basis' => 'source_supported',
            'options' => [
                ['id' => 'A', 'text' => '3:30'], ['id' => 'B', 'text' => '6:30'],
                ['id' => 'C', 'text' => '6:00'], ['id' => 'D', 'text' => '3:00'],
            ],
            'correct_option_id' => 'A',
            'explanation' => 'The minute hand at 6 means half past.',
            'source_refs' => [$chunk->id],
        ]);

        $this->actingAs($user)->get(route('assessments.show', $assessment))
            ->assertRedirect(route('study.show', $space))
            ->assertSessionHasErrors('practice');
        $this->assertNull($assessment->fresh()->started_at);

        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => (string) str()->uuid(),
        ])->assertRedirect(route('study.show', $space))->assertSessionHasErrors('practice');
        $this->assertDatabaseCount('assessment_attempts', 0);
        $this->assertNull($assessment->fresh()->started_at);
    }

    public function test_learner_can_generate_take_and_idempotently_retry_source_grounded_practice(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();

        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]))
            ->assertRedirect();
        $assessment = Assessment::with('questions')->firstOrFail();
        $source = MaterialChunk::with('material')->findOrFail($assessment->questions->first()->source_refs[0]);
        $this->assertTrue(Str::isUuid($assessment->id));
        $this->assertCount(4, $assessment->questions);
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->assertNotNull($written);
        $this->assertSame(5, $written->max_points);
        $this->assertSame('supplementary', $written->knowledge_basis);
        foreach ($assessment->questions as $question) {
            $this->assertTrue(Str::isUuid($question->id));
        }

        $this->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Submit practice')
            ->assertDontSee('Correct answer')
            ->assertDontSee('correct_option_id')
            ->assertDontSee('Identifies light energy.')
            ->assertSee('Your answer')
            ->assertSee('Beyond this material:')
            ->assertSee('Topic source:')
            ->assertSee($source->material->title)
            ->assertSee('page 2')
            ->assertDontSee('How to work it out');

        $uuid = '35197cb9-bbc8-4d7d-a4cb-69db9335a95d';
        $wrongAnswers = $assessment->questions->where('question_type', 'multiple_choice')->mapWithKeys(fn ($question) => [$question->id => 'B'])->all();
        $this->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => $uuid,
            'answers' => $wrongAnswers,
            'responses' => [$written->id => 'Plants use light energy to make glucose.'],
        ])
            ->assertRedirect();
        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertTrue(Str::isUuid($attempt->id));
        $this->assertSame(0, $attempt->correct_count);
        $this->assertDatabaseCount('assessment_answers', 4);
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_question_id' => $written->id,
            'written_response' => 'Plants use light energy to make glucose.',
            'marking_status' => 'marked',
            'points_awarded' => 3,
        ]);
        foreach ($attempt->answers as $answer) {
            $this->assertTrue(Str::isUuid($answer->id));
        }

        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('0 of 3 multiple-choice answers correct')
            ->assertSee('Provisional AI feedback')
            ->assertSee('Latest learning signal: 38% across 3 of 8 points.')
            ->assertSee('How to work it out')
            ->assertSee('A worked answer, step by step')
            ->assertSee('Identify the evidence')
            ->assertSee('Page 2')
            ->assertSee('Review this idea before moving on')
            ->assertSee('Ask the tutor about Photosynthesis');

        $correctAnswers = $assessment->questions->where('question_type', 'multiple_choice')->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $this->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => $uuid,
            'answers' => $correctAnswers,
            'responses' => [$written->id => 'Different answer should be ignored because this is idempotent.'],
        ])
            ->assertRedirect(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]));
        $this->assertDatabaseCount('assessment_attempts', 1);
        $this->assertDatabaseCount('assessment_answers', 4);
        $this->assertDatabaseHas('assessment_attempts', ['id' => $attempt->id, 'correct_count' => 0]);
    }

    public function test_offline_json_submission_returns_an_idempotent_result_for_the_owner(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]));
        $assessment = Assessment::with('questions')->firstOrFail();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $answers = $assessment->questions->where('question_type', 'multiple_choice')->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $payload = [
            'attempt_uuid' => '9f4c7c4b-8180-4d18-b9b6-b3c334b832a4',
            'answers' => $answers,
            'responses' => [$written->id => ''],
        ];

        $this->postJson(route('assessments.submit', $assessment), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'synced')
            ->assertJsonStructure(['attempt_id', 'result_url']);
        $attemptId = $assessment->attempts()->sole()->id;

        $this->postJson(route('assessments.submit', $assessment), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'synced')
            ->assertJsonPath('attempt_id', $attemptId);
        $this->assertDatabaseCount('assessment_attempts', 1);
        $this->assertDatabaseCount('assessment_answers', 4);
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_question_id' => $written->id,
            'written_response' => '',
            'marking_status' => 'not_applicable',
            'points_awarded' => null,
        ]);
        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attemptId]))
            ->assertOk()
            ->assertSee('No written response was submitted, so no written feedback was generated.')
            ->assertDontSee('Written feedback is AI-generated and provisional');

        $other = User::factory()->create();
        $this->actingAs($other)->postJson(route('assessments.submit', $assessment), $payload)->assertNotFound();
    }

    public function test_written_marking_failure_keeps_response_and_can_be_retried_by_its_owner(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]));
        $assessment = Assessment::with('questions')->firstOrFail();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->app->instance(AnswerMarker::class, new class implements AnswerMarker
        {
            public function mark(string $question, string $knowledgeBasis, array $rubric, string $learnerResponse, array $evidence): array
            {
                throw new AnswerMarkingException('Do not disclose provider details.');
            }
        });
        $answers = $assessment->questions->where('question_type', 'multiple_choice')->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => (string) str()->uuid(), 'answers' => $answers,
            'responses' => [$written->id => 'My saved response.'],
        ])->assertRedirect();
        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_question_id' => $written->id,
            'written_response' => 'My saved response.',
            'marking_status' => 'failed',
        ]);

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('assessments.mark-open-ended', [$assessment, $attempt]))->assertNotFound();

        $this->app->instance(AnswerMarker::class, $this->fakeAnswerMarker());
        $this->actingAs($user)->post(route('assessments.mark-open-ended', [$assessment, $attempt]))
            ->assertRedirect(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]));
        $this->assertDatabaseHas('assessment_answers', [
            'assessment_question_id' => $written->id,
            'written_response' => 'My saved response.',
            'marking_status' => 'marked',
        ]);
    }

    public function test_ai_returns_needs_review_without_recording_a_score(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]));
        $assessment = Assessment::with('questions')->firstOrFail();
        $written = $assessment->questions->firstWhere('question_type', 'open_ended');
        $this->app->instance(AnswerMarker::class, new class implements AnswerMarker
        {
            public function mark(string $question, string $knowledgeBasis, array $rubric, string $learnerResponse, array $evidence): array
            {
                return ['status' => 'needs_review', 'points_awarded' => null, 'feedback' => 'The sources do not support a reliable mark.', 'criteria_results' => []];
            }
        });
        $answers = $assessment->questions->where('question_type', 'multiple_choice')->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $this->actingAs($user)->post(route('assessments.submit', $assessment), [
            'attempt_uuid' => (string) str()->uuid(), 'answers' => $answers,
            'responses' => [$written->id => 'My response.'],
        ])->assertRedirect();

        $attempt = $assessment->attempts()->firstOrFail();
        $answer = $attempt->answers()->where('assessment_question_id', $written->id)->firstOrFail();
        $this->assertSame('needs_review', $answer->marking_status);
        $this->assertNull($answer->points_awarded);
        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('No score was assigned.')
            ->assertSee('Try a new practice set')
            ->assertSee('id="new-practice"', false);
    }

    public function test_only_the_owner_can_generate_or_view_an_assessment(): void
    {
        [$owner, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();
        $this->actingAs($owner)->post(route('assessments.store', [$space, $concept]));
        $assessment = Assessment::firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('assessments.show', $assessment))->assertNotFound();
        $this->actingAs($other)->post(route('assessments.store', [$space, $concept]))->assertNotFound();
    }

    public function test_assessment_creation_rejects_a_concept_from_another_space(): void
    {
        $owner = User::factory()->create();
        $firstSpace = LearningSpace::factory()->for($owner)->create();
        $secondSpace = LearningSpace::factory()->for($owner)->create();
        $concept = $secondSpace->concepts()->create(['name' => 'Unrelated', 'description' => 'Other space']);
        $this->actingAs($owner)->post(route('assessments.store', [$firstSpace, $concept]))->assertNotFound();
    }

    public function test_assessment_generation_requires_source_chunks(): void
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $concept = $space->concepts()->create(['name' => 'Unproven', 'description' => 'No source attached.']);
        $this->bindFakeGenerator();

        $this->actingAs($user)->from(route('study.show', $space))
            ->post(route('assessments.store', [$space, $concept]))
            ->assertRedirect(route('study.show', $space))
            ->assertSessionHasErrors('practice');
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_supplementary_stem_questions_are_clearly_distinguished_from_the_source(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $chunk = $concept->sources()->firstOrFail();
        $assessment = $space->assessments()->create(['concept_id' => $concept->id, 'prompt_version' => 'grounded-mixed-practice-v7']);
        $assessment->questions()->create([
            'concept_id' => $concept->id,
            'knowledge_basis' => 'supplementary',
            'prompt' => 'A plant receives light. Which substance does it make during photosynthesis?',
            'options' => [
                ['id' => 'A', 'text' => 'Glucose'], ['id' => 'B', 'text' => 'Sand'],
                ['id' => 'C', 'text' => 'Iron'], ['id' => 'D', 'text' => 'Salt'],
            ],
            'correct_option_id' => 'A', 'explanation' => 'Plants use light in photosynthesis to make glucose.',
            'solution_steps' => [
                ['title' => 'Use the source', 'content' => 'The material says plants use light to make glucose.'],
                ['title' => 'Choose the matching option', 'content' => 'Glucose is the substance named in the source.'],
            ],
            'source_refs' => [$chunk->id],
        ]);

        $this->actingAs($user)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('A plant receives light.')
            ->assertSee('Beyond this material:')
            ->assertSee('This question uses standard subject knowledge to help you explore the topic further.')
            ->assertSee('page 2');
    }

    public function test_revision_advisor_selects_the_lowest_measured_prerequisite(): void
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $strong = $space->concepts()->create(['name' => 'Strong foundation', 'description' => 'Known well.']);
        $weak = $space->concepts()->create(['name' => 'Weak foundation', 'description' => 'Needs review.']);
        $target = $space->concepts()->create(['name' => 'Dependent concept', 'description' => 'Builds on both.']);
        $target->prerequisites()->attach([$strong->id, $weak->id]);
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);

        foreach ([[$strong, true], [$weak, false]] as $index => [$concept, $correct]) {
            $source = $material->chunks()->create([
                'extraction_version' => 1, 'content' => $concept->description, 'page' => $index + 1,
                'locator' => ['page' => $index + 1], 'relevance_status' => 'related',
            ]);
            $concept->sources()->attach($source);
            $assessment = $space->assessments()->create(['concept_id' => $concept->id, 'prompt_version' => 'grounded-quiz-v1']);
            $question = $assessment->questions()->create([
                'concept_id' => $concept->id, 'prompt' => 'Practice item', 'options' => [],
                'correct_option_id' => 'A', 'explanation' => 'Evidence.', 'source_refs' => [],
            ]);
            $attempt = $assessment->attempts()->create([
                'user_id' => $user->id, 'client_attempt_uuid' => (string) str()->uuid(),
                'correct_count' => $correct ? 1 : 0, 'question_count' => 1, 'submitted_at' => now(),
            ]);
            $attempt->answers()->create([
                'assessment_question_id' => $question->id, 'selected_option_id' => 'A', 'correct' => $correct,
            ]);
        }

        $recommendation = app(AssessmentProgress::class)->weakestPrerequisite($space, $target->id, $user);

        $this->assertSame($weak->id, $recommendation['concept']->id);
        $this->assertSame(0, $recommendation['progress']['percentage']);
    }

    public function test_learning_signal_uses_the_latest_attempt_and_includes_marked_written_feedback(): void
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $concept = $space->concepts()->create(['name' => 'Nigeria history', 'description' => 'A source-based concept.']);

        foreach ([['correct' => 0, 'written' => 0, 'submitted_at' => now()->subDay()], ['correct' => 2, 'written' => 4, 'submitted_at' => now()]] as $attemptData) {
            $assessment = $space->assessments()->create(['concept_id' => $concept->id, 'prompt_version' => 'grounded-mixed-practice-v7']);
            $attempt = $assessment->attempts()->create([
                'user_id' => $user->id, 'client_attempt_uuid' => (string) str()->uuid(),
                'correct_count' => $attemptData['correct'], 'question_count' => 3,
                'submitted_at' => $attemptData['submitted_at'],
            ]);
            for ($index = 0; $index < 3; $index++) {
                $question = $assessment->questions()->create([
                    'concept_id' => $concept->id, 'prompt' => 'Select the source-backed idea.', 'options' => [],
                    'correct_option_id' => 'A', 'explanation' => 'Source evidence.', 'source_refs' => [],
                ]);
                $attempt->answers()->create([
                    'assessment_question_id' => $question->id, 'selected_option_id' => $index < $attemptData['correct'] ? 'A' : 'B',
                    'correct' => $index < $attemptData['correct'],
                ]);
            }
            $written = $assessment->questions()->create([
                'concept_id' => $concept->id, 'question_type' => 'open_ended', 'prompt' => 'Explain the idea.',
                'options' => [], 'correct_option_id' => '', 'explanation' => '', 'source_refs' => [], 'max_points' => 5,
            ]);
            $attempt->answers()->create([
                'assessment_question_id' => $written->id, 'selected_option_id' => '', 'correct' => false,
                'written_response' => 'My response.', 'marking_status' => 'marked',
                'points_awarded' => $attemptData['written'], 'max_points' => 5,
            ]);
        }

        $progress = app(AssessmentProgress::class)->forSpace($space, $user)[$concept->id];

        $this->assertSame(3, $progress['answered']);
        $this->assertSame(2, $progress['correct']);
        $this->assertSame(6, $progress['score_points']);
        $this->assertSame(8, $progress['possible_points']);
        $this->assertSame(75, $progress['percentage']);
        $this->assertSame(4, $progress['written_points']);
        $this->assertSame(5, $progress['written_max_points']);
    }

    /** @return array{User, LearningSpace, Concept} */
    private function spaceWithSourceConcept(): array
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $prerequisite = $space->concepts()->create(['name' => 'Foundations', 'description' => 'A prerequisite idea.']);
        $concept = $space->concepts()->create(['name' => 'Photosynthesis', 'description' => 'How plants convert light to chemical energy.']);
        $concept->prerequisites()->attach($prerequisite->id);
        $material = Material::factory()->for($space)->create(['status' => 'ready']);
        $chunk = $material->chunks()->create(['extraction_version' => 1, 'content' => 'Plants use light energy to convert carbon dioxide and water into glucose and oxygen.', 'page' => 2, 'locator' => []]);
        $concept->sources()->attach($chunk->id);
        $chunk->forceFill(['relevance_status' => 'related'])->save();

        return [$user, $space, $concept];
    }

    private function bindFakeGenerator(): void
    {
        $this->app->instance(QuizGenerator::class, new class implements QuizGenerator
        {
            public function generate(string $conceptName, array $evidence, int $count = 3, string $mode = 'guided'): array
            {
                return array_fill(0, $count, [
                    'prompt' => 'A plant uses light during '.$conceptName.'. What does it produce according to the notes?',
                    'options' => [
                        ['id' => 'A', 'text' => 'Plants use light energy to form glucose.'],
                        ['id' => 'B', 'text' => 'Plants use moonlight to form proteins.'],
                        ['id' => 'C', 'text' => 'Plants convert oxygen into sunlight.'],
                        ['id' => 'D', 'text' => 'Plants obtain glucose from soil minerals.'],
                    ],
                    'correct_option_id' => 'A',
                    'explanation' => 'The cited source says plants use light energy to make glucose.',
                    'solution_steps' => [
                        ['title' => 'Identify the evidence', 'content' => 'The source says plants use light energy.'],
                        ['title' => 'Connect it to the answer', 'content' => 'The source says plants make glucose.'],
                    ],
                    'knowledge_basis' => 'source_supported',
                    'source_chunk_ids' => [$evidence[0]['id']],
                ]);
            }
        });
        $this->app->instance(OpenEndedQuestionGenerator::class, new class implements OpenEndedQuestionGenerator
        {
            public function generate(string $conceptName, array $evidence, string $mode = 'guided'): array
            {
                return [
                    'prompt' => 'Explain how plants use light. Identify the energy input and explain the product formed.',
                    'knowledge_basis' => $mode === 'exam' ? 'source_supported' : 'supplementary',
                    'solution_steps' => [
                        ['title' => 'Identify the input', 'content' => 'Plants use light energy.'],
                        ['title' => 'Explain the result', 'content' => 'Light energy supports glucose production.'],
                    ],
                    'rubric' => [
                        ['id' => 'C1', 'description' => 'Identifies light energy.', 'prompt_reference' => 'Identify the energy input', 'points' => 2, 'source_chunk_ids' => [$evidence[0]['id']]],
                        ['id' => 'C2', 'description' => 'Explains glucose production.', 'prompt_reference' => 'explain the product formed', 'points' => 3, 'source_chunk_ids' => [$evidence[0]['id']]],
                    ],
                    'max_points' => 5,
                    'source_chunk_ids' => [$evidence[0]['id']],
                ];
            }
        });
        $this->app->instance(AnswerMarker::class, $this->fakeAnswerMarker());
    }

    private function fakeAnswerMarker(): AnswerMarker
    {
        return new class implements AnswerMarker
        {
            public function mark(string $question, string $knowledgeBasis, array $rubric, string $learnerResponse, array $evidence): array
            {
                return [
                    'status' => 'marked', 'points_awarded' => 3, 'feedback' => 'Good explanation with room to expand.',
                    'criteria_results' => [
                        ['criterion_id' => 'C1', 'points_awarded' => 1, 'feedback' => 'You identified light energy.', 'source_chunk_ids' => [$evidence[0]['id']]],
                        ['criterion_id' => 'C2', 'points_awarded' => 2, 'feedback' => 'You described glucose production.', 'source_chunk_ids' => [$evidence[0]['id']]],
                    ],
                ];
            }
        };
    }
}
