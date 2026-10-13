<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\MaterialChunk;
use App\Models\User;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\StudyPathGuide;
use App\Services\Learning\Tutor;
use App\Services\Learning\TutorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyPathTutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_tutor_chat_renders_markdown_and_safely_strips_untrusted_html(): void
    {
        $space = LearningSpace::factory()->create();
        $message = $space->tutorMessages()->create([
            'role' => 'assistant',
            'content' => "**Key idea**\n\n- First step\n- Second step\n\n<script>alert(1)</script>",
            'citations' => [],
            'supplementary_sections' => [],
            'language' => 'yo',
        ]);

        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('data-read-aloud-language="yo"', false)
            ->assertSee('data-read-aloud-target="tutor-answer-'.$message->id.'"', false)
            ->assertSee('<audio class="read-aloud-player" controls', false)
            ->assertSee('<audio class="read-aloud-player" controls hidden', false)
            ->assertSee('role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation with your tutor"', false)
            ->assertDontSee('AI-generated speech')
            ->assertDontSee('AI voice')
            ->assertDontSee('The text you select is sent')
            ->assertSee('<strong>Key idea</strong>', false)
            ->assertSee('<li>First step</li>', false)
            ->assertDontSee('**Key idea**')
            ->assertDontSee('<script>', false);
    }

    public function test_path_is_deterministic_and_orders_prerequisites_first(): void
    {
        $space = LearningSpace::factory()->create();
        $advanced = $space->concepts()->create(['name' => 'Applications', 'description' => 'Use the idea.']);
        $foundation = $space->concepts()->create(['name' => 'Basics', 'description' => 'Learn the idea.']);
        $advanced->prerequisites()->attach($foundation, ['source_supported' => true]);
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        foreach ([$foundation, $advanced] as $index => $concept) {
            $source = $material->chunks()->create([
                'extraction_version' => 1, 'content' => $concept->description, 'page' => $index + 1,
                'locator' => ['page' => $index + 1], 'relevance_status' => 'related',
            ]);
            $concept->sources()->attach($source);
        }

        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSeeTextInOrder(['Basics', 'Applications'])
            ->assertSee('Your recommended next step')
            ->assertSee('Suggested 15-minute session')
            ->assertSee('Learn · 5 min')
            ->assertSee('Start session')
            ->assertSee('Suggested order builds one idea on the last. You can explore ahead, then ask the tutor to revisit a foundation if something feels unclear.')
            ->assertSee('Read and practise')
            ->assertSee('data-read-aloud-target="concept-explanation-'.$foundation->id.'"', false)
            ->assertSee('Read the source evidence')
            ->assertSee('0 of 2 concepts practised')
            ->assertSee('Practise each concept once to complete this path')
            ->assertSee('Ask the tutor about this');
        $this->actingAs($space->user)->get(route('study.show', $space))->assertOk();

        $this->assertDatabaseCount('learning_paths', 1);
        $path = $space->learningPaths()->firstOrFail()->load('items.concept');
        $this->assertSame([$foundation->id, $advanced->id], $path->items->pluck('concept_id')->all());

        $nextStep = app(StudyPathGuide::class)->nextStep($path, [
            $foundation->id => ['answered' => 3, 'correct' => 3, 'percentage' => 100],
            $advanced->id => ['answered' => 4, 'correct' => 2, 'percentage' => 50],
        ]);
        $this->assertSame($advanced->id, $nextStep['item']->concept_id);
        $this->assertTrue($nextStep['needs_review']);

        $progressService = app(AssessmentProgress::class);
        $this->assertSame([
            'total_concepts' => 2,
            'practised_concepts' => 0,
            'percentage' => 0,
            'complete' => false,
        ], $progressService->pathSummary($path, []));
        $this->assertSame([
            'total_concepts' => 2,
            'practised_concepts' => 2,
            'percentage' => 100,
            'complete' => true,
        ], $progressService->pathSummary($path, [
            $foundation->id => ['answered' => 3, 'correct' => 1, 'percentage' => 33],
            $advanced->id => ['answered' => 4, 'correct' => 2, 'percentage' => 50],
        ]));

        $this->actingAs($space->user)->get(route('study.show', $space).'?concept='.$foundation->id)
            ->assertOk()
            ->assertSee('Teach me &quot;Basics&quot; in a short step-by-step lesson grounded in my uploaded materials', false)
            ->assertSee('Cite the source page for each important point')
            ->assertSee('in this subject or skill')
            ->assertSee('name="concept_id" value="'.$foundation->id.'"', false);
    }

    public function test_study_path_shows_drawings_for_only_learner_confirmed_clock_examples(): void
    {
        $space = LearningSpace::factory()->create();
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $confirmedConcept = $space->concepts()->create([
            'name' => 'Reading times from analogue clocks',
            'description' => 'The clock diagrams show times on the hour and half past the hour.',
        ]);
        $confirmedSource = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Confirmed clock readings.',
            'page' => 1,
            'locator' => [
                'clock_diagrams' => [
                    'status' => 'learner_confirmed',
                    'faces' => [
                        ['index' => 1, 'confirmed_time' => '03:00'],
                        ['index' => 2, 'confirmed_time' => '05:30'],
                        ['index' => 3, 'confirmed_time' => '08:00'],
                        ['index' => 4, 'confirmed_time' => '01:30'],
                        ['index' => 5, 'confirmed_time' => '08:30'],
                        ['index' => 6, 'confirmed_time' => '07:30'],
                        ['index' => 7, 'confirmed_time' => '13:00'],
                    ],
                ],
            ],
            'relevance_status' => 'related',
        ]);
        $confirmedConcept->sources()->attach($confirmedSource);

        $unconfirmedConcept = $space->concepts()->create([
            'name' => 'Unconfirmed clock page',
            'description' => 'A clock suggestion still needs review.',
        ]);
        $unconfirmedSource = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Suggested clock readings.',
            'page' => 2,
            'locator' => ['clock_diagrams' => [
                'status' => 'needs_review',
                'faces' => [['index' => 1, 'candidate_time' => '10:00']],
            ]],
            'relevance_status' => 'related',
        ]);
        $unconfirmedConcept->sources()->attach($unconfirmedSource);

        $response = $this->actingAs($space->user)->get(route('study.show', $space))->assertOk()
            ->assertSee('These clock drawings recreate the times you checked against the original page. They are not images from the source.')
            ->assertSee('Clock 1 · 03:00 · page 1')
            ->assertSee('Clock 6 · 07:30 · page 1')
            ->assertDontSee('Clock 7 · 13:00')
            ->assertDontSee('Clock 1 · 10:00');

        $this->assertSame(6, substr_count($response->getContent(), '<svg class="confirmed-clock-face"'));
    }

    public function test_another_learner_cannot_view_a_path_or_ask_its_tutor(): void
    {
        $space = LearningSpace::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('study.show', $space))->assertForbidden();
        $this->actingAs($other)->post(route('tutor.ask', $space), ['question' => 'Explain this'])->assertForbidden();
    }

    public function test_selected_tutor_topic_cannot_be_from_another_learning_space(): void
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $otherSpace = LearningSpace::factory()->for($user)->create();
        $material = Material::factory()->for($otherSpace)->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'content' => 'This evidence belongs only to another study goal.', 'page' => 1,
            'locator' => ['page' => 1], 'relevance_status' => 'related',
        ]);
        $otherConcept = $otherSpace->concepts()->create(['name' => 'Other goal', 'description' => 'This is private to another goal.']);
        $otherConcept->sources()->attach($chunk);

        $this->actingAs($user)->post(route('tutor.ask', $space), [
            'question' => 'Teach me this topic.', 'concept_id' => $otherConcept->id,
        ])->assertNotFound();
    }

    public function test_tutor_answer_persists_resolvable_citations_from_only_the_owned_space(): void
    {
        [$space, $chunk] = $this->readyChunk('Photosynthesis stores light energy as chemical energy.');
        $otherChunk = $this->readyChunk('Photosynthesis is discussed elsewhere.')[1];
        $excludedChunk = $chunk->material->chunks()->create([
            'extraction_version' => 1, 'content' => 'Photosynthesis unrelated context within a separate page.', 'page' => 2,
            'locator' => ['page' => 2], 'relevance_status' => 'separate',
        ]);
        $this->app->bind(Tutor::class, fn () => new class($chunk->id, $otherChunk->id) implements Tutor
        {
            public function __construct(private int $ownedId, private int $otherId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame([$this->ownedId], array_column($chunks, 'id'));
                TestCase::assertNotContains($this->otherId, array_column($chunks, 'id'));
                TestCase::assertCount(1, $chunks);

                return [
                    'answer' => 'It stores light as chemical energy.',
                    'citations' => [['chunk_id' => $this->ownedId, 'excerpt' => 'stores light energy as chemical energy']],
                    'supplementary' => [], 'insufficient' => false,
                ];
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'How does photosynthesis store energy?'])
            ->assertRedirect()
            ->assertSessionMissing('_old_input');
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSee('It stores light as chemical energy.')->assertSee('Page 1');
        $this->assertDatabaseHas('tutor_messages', ['learning_space_id' => $space->id, 'role' => 'assistant', 'status' => 'completed']);
    }

    public function test_missing_retrieval_evidence_returns_explicit_response_without_calling_provider(): void
    {
        [$space] = $this->readyChunk('Mitochondria produce cellular energy.');
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                throw new \LogicException('Provider must not be called without evidence.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Who wrote Hamlet?'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('tutor_messages', [
            'learning_space_id' => $space->id,
            'insufficient_evidence' => true,
            'content' => 'I could not find enough detail in your study materials to answer that. Try asking about one idea in your study path.',
        ]);
    }

    public function test_tutor_follow_up_includes_the_recent_conversation_for_continuous_teaching(): void
    {
        [$space, $chunk] = $this->readyChunk('Photosynthesis stores light energy in glucose.');
        $space->tutorMessages()->create(['role' => 'user', 'content' => 'Where does the energy go?', 'citations' => [], 'supplementary_sections' => []]);
        $space->tutorMessages()->create(['role' => 'assistant', 'content' => 'It is stored in glucose. What happens when the plant uses that glucose?', 'citations' => [], 'supplementary_sections' => []]);
        $this->app->bind(Tutor::class, fn () => new class($chunk->id) implements Tutor
        {
            public function __construct(private int $chunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame([
                    ['role' => 'user', 'content' => 'Where does the energy go?'],
                    ['role' => 'assistant', 'content' => 'It is stored in glucose. What happens when the plant uses that glucose?'],
                ], $conversation);

                return ['answer' => 'That glucose stores the energy.', 'citations' => [['chunk_id' => $this->chunkId, 'excerpt' => 'stores light energy in glucose']], 'supplementary' => [], 'insufficient' => false];
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Can you connect that to energy?'])->assertRedirect();
        $this->assertDatabaseHas('tutor_messages', ['learning_space_id' => $space->id, 'role' => 'assistant', 'content' => 'That glucose stores the energy.']);
    }

    public function test_tutor_check_support_signal_is_saved_and_recommends_another_explanation(): void
    {
        [$space, $chunk] = $this->readyChunk('The pendulum changes between kinetic and potential energy as it swings.');
        $concept = $space->concepts()->create(['name' => 'Energy in a swinging pendulum', 'description' => 'Energy changes as the pendulum moves.']);
        $concept->sources()->attach($chunk);
        $space->learningPaths()->create(['goal' => 'Understand the source'])->items()->create([
            'concept_id' => $concept->id, 'position' => 1,
        ]);
        $space->tutorMessages()->create(['role' => 'assistant', 'content' => 'What changes as the pendulum swings?', 'citations' => [], 'supplementary_sections' => []]);
        $this->app->bind(Tutor::class, fn () => new class($chunk->id) implements Tutor
        {
            public function __construct(private int $chunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                return ['answer' => 'You have part of it. Let us try a smaller step.', 'citations' => [['chunk_id' => $this->chunkId, 'excerpt' => 'kinetic and potential energy']], 'supplementary' => [], 'insufficient' => false, 'understandingCheck' => 'needs_support'];
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), [
            'question' => 'It moves up and down.', 'concept_id' => $concept->id,
        ])->assertRedirect();

        $message = $space->tutorMessages()->where('role', 'assistant')->where('understanding_check', 'needs_support')->firstOrFail();
        $this->assertSame($concept->id, $message->concept_id);
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Your tutor check suggests another explanation would help before you move on.')
            ->assertSee('Tutor check, formative and not a score: another explanation or a smaller guided step may help.');
        $this->actingAs($space->user)->get(route('study.show', $space).'?concept='.$concept->id.'#question')
            ->assertOk()
            ->assertSee('I am still unsure about &quot;Energy in a swinging pendulum&quot;. Please explain just the next smallest idea in simpler words', false)
            ->assertSee('without giving away the answer');
    }

    public function test_provider_timeout_is_visible_and_does_not_expose_provider_payloads(): void
    {
        [$space] = $this->readyChunk('Gravity attracts masses toward each other.');
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                throw new TutorException('The tutor timed out. Please try again.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Explain gravity'])->assertRedirect();
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSee('The tutor timed out. Please try again.')
            ->assertSee('Your question is saved in the conversation above.')
            ->assertSee('Continue with your learning path')
            ->assertSee('Explain gravity');
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSee('>Explain gravity</textarea>', false);
        $this->assertDatabaseHas('tutor_messages', ['learning_space_id' => $space->id, 'status' => 'failed']);
    }

    public function test_legacy_internal_citation_failure_is_explained_in_learner_language(): void
    {
        [$space] = $this->readyChunk('Gravity attracts masses toward each other.');
        $space->tutorMessages()->create(['role' => 'user', 'content' => 'Explain gravity in simple terms.', 'citations' => [], 'supplementary_sections' => []]);
        $space->tutorMessages()->create([
            'role' => 'assistant', 'content' => '', 'citations' => [], 'supplementary_sections' => [],
            'status' => 'failed', 'failure_message' => 'The tutor returned an unresolved citation.',
        ]);

        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('I could not verify a reliable answer from your materials.')
            ->assertDontSee('unresolved citation')
            ->assertSee('>Explain gravity in simple terms.</textarea>', false);
    }

    public function test_unverified_tutor_citation_falls_back_to_a_source_linked_guided_study_note(): void
    {
        [$space, $chunk] = $this->readyChunk('The conquest ended in 1903 and north and south were joined in 1914.');
        $concept = $space->concepts()->create([
            'name' => 'Colonial formation of Nigeria',
            'description' => 'British conquest ended in 1903, then northern and southern Nigeria were joined in 1914.',
        ]);
        $concept->sources()->attach($chunk);
        $unrelatedChunk = $chunk->material->chunks()->create([
            'extraction_version' => 1, 'content' => 'Nigeria art history includes Ife and Benin sculpture traditions.', 'page' => 2,
            'locator' => ['page' => 2], 'relevance_status' => 'related', 'relevance_confidence' => 1,
        ]);
        $unrelatedConcept = $space->concepts()->create([
            'name' => 'Artistic production in Ife and Benin',
            'description' => 'Nigeria art history includes sculpture and metal casting.',
        ]);
        $unrelatedConcept->sources()->attach($unrelatedChunk);
        $this->app->bind(Tutor::class, fn () => new class($chunk->id) implements Tutor
        {
            public function __construct(private int $targetChunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame([$this->targetChunkId], array_column($chunks, 'id'));

                throw new TutorException('The tutor returned an unresolved citation.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), [
            'question' => 'Teach me the colonial formation of Nigeria.',
            'concept_id' => $concept->id,
        ])->assertRedirect()->assertSessionHas('status');

        $message = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('fallback', $message->status);
        $this->assertStringContainsString('Simple idea: '.$concept->description, $message->content);
        $this->assertStringContainsString('Check your understanding', $message->content);
        $this->assertSame([['chunk_id' => $chunk->id, 'excerpt' => $chunk->content]], $message->citations);
        $this->assertStringNotContainsString($unrelatedConcept->name, $message->content);
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Source passages for your selected topic')
            ->assertSee('I could not verify a full AI explanation, so I am using the source-backed lesson for Colonial formation of Nigeria.')
            ->assertSee('Page 1');
    }

    public function test_unverified_general_tutor_answer_never_falls_back_to_a_similar_but_unrequested_topic(): void
    {
        [$space, $targetChunk] = $this->readyChunk('British conquest ended in 1903 and territories joined in 1914.');
        $space->update(['preferred_language' => 'yo']);
        $targetConcept = $space->concepts()->create([
            'name' => 'Colonial formation of Nigeria',
            'description' => 'British conquest ended in 1903, then northern and southern Nigeria were joined in 1914.',
        ]);
        $targetConcept->sources()->attach($targetChunk);
        $unrelatedChunk = $targetChunk->material->chunks()->create([
            'extraction_version' => 1, 'content' => 'Nigeria art history includes Ife and Benin sculpture traditions.', 'page' => 2,
            'locator' => ['page' => 2], 'relevance_status' => 'related', 'relevance_confidence' => 1,
        ]);
        $unrelatedConcept = $space->concepts()->create([
            'name' => 'Artistic production in Ife and Benin',
            'description' => 'Nigeria art history includes sculpture and metal casting.',
        ]);
        $unrelatedConcept->sources()->attach($unrelatedChunk);
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame('yo', $language);

                throw new TutorException('The tutor returned an unresolved citation.');
            }
        });

        $this->actingAs($space->user)->from(route('study.show', $space))
            ->post(route('tutor.ask', $space), ['question' => 'Explain Nigeria.'])
            ->assertRedirect(route('study.show', $space));

        $message = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertSame('yo', $message->language);
        $this->assertStringContainsString('Mi ò lè fìdí ìdáhùn tó ṣeé gbẹ́kẹ̀ lé múlẹ̀', $message->failure_message);
        $this->assertStringNotContainsString($unrelatedConcept->name, $message->content.$message->failure_message);
        $this->assertStringNotContainsString($targetConcept->name, $message->content.$message->failure_message);
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Mi ò lè fìdí ìdáhùn tó ṣeé gbẹ́kẹ̀ lé múlẹ̀')
            ->assertSee('Explain Nigeria.');
    }

    /** @return array{LearningSpace, MaterialChunk} */
    private function readyChunk(string $content): array
    {
        $space = LearningSpace::factory()->create();
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'content' => $content, 'page' => 1, 'locator' => ['page' => 1],
            'relevance_status' => 'related', 'relevance_confidence' => 1,
            'relevance_reason' => 'Fixture evidence is confirmed as aligned with the goal.',
        ]);

        return [$space, $chunk];
    }
}
