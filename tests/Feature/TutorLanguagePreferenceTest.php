<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\User;
use App\Services\Learning\Tutor;
use App\Services\Learning\TutorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorLanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_supported_tutor_language_and_new_answers_keep_exact_source_quotes(): void
    {
        $space = LearningSpace::factory()->create();
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Photosynthesis stores light energy in glucose.',
            'page' => 2,
            'locator' => ['page' => 2],
            'relevance_status' => 'related',
            'relevance_confidence' => 1,
        ]);

        $this->actingAs($space->user)
            ->post(route('tutor.language.update', $space), ['preferred_language' => 'ha'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Tutor language updated. This applies to new tutor replies only.');
        $this->assertDatabaseHas('learning_spaces', ['id' => $space->id, 'preferred_language' => 'ha']);

        $this->app->bind(Tutor::class, fn () => new class($chunk->id) implements Tutor
        {
            public function __construct(private int $chunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame('ha', $language);

                return [
                    'answer' => 'Photosynthesis na adana makamashin haske a cikin glucose.',
                    'citations' => [['chunk_id' => $this->chunkId, 'excerpt' => 'stores light energy in glucose']],
                    'supplementary' => [],
                    'insufficient' => false,
                ];
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Explain photosynthesis.'])->assertRedirect();
        $answer = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('Photosynthesis na adana makamashin haske a cikin glucose.', $answer->content);
        $this->assertSame([['chunk_id' => $chunk->id, 'excerpt' => 'stores light energy in glucose']], $answer->citations);

        $this->view('study.partials.tutor-language', ['learningSpace' => $space->fresh()])
            ->assertSee('Tutor reply language')
            ->assertSee('Source quotations stay in their original wording.')
            ->assertSee('value="ha" selected', false)
            ->assertSee('Hausa')
            ->assertSee('Yoruba')
            ->assertSee('Igbo')
            ->assertDontSee('Portuguese')
            ->assertDontSee('Arabic');
    }

    public function test_other_learner_cannot_change_a_space_language_and_unsupported_language_is_rejected(): void
    {
        $space = LearningSpace::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('tutor.language.update', $space), ['preferred_language' => 'ar'])
            ->assertForbidden();

        $this->actingAs($space->user)
            ->from(route('study.show', $space))
            ->post(route('tutor.language.update', $space), ['preferred_language' => 'fr'])
            ->assertSessionHasErrors('preferred_language');

        $this->assertSame('en', $space->fresh()->preferred_language);
    }

    public function test_language_save_from_a_stale_study_space_returns_to_materials_with_recovery_guidance(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/spaces/999999/tutor-language', ['preferred_language' => 'yo'])
            ->assertRedirect(route('materials.index'))
            ->assertSessionHas('status', 'That study space is no longer available. Choose another study space to continue.');
    }

    public function test_switching_reply_language_keeps_prior_questions_but_excludes_other_language_tutor_replies(): void
    {
        $space = LearningSpace::factory()->create(['preferred_language' => 'yo']);
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'The Sokoto Caliphate united much of northern Nigeria under Islamic government.',
            'page' => 3,
            'locator' => ['page' => 3],
            'relevance_status' => 'related',
            'relevance_confidence' => 1,
        ]);
        $space->tutorMessages()->create([
            'role' => 'user', 'content' => 'Explain this history.', 'citations' => [], 'supplementary_sections' => [], 'language' => 'yo',
            'created_at' => now()->subMinutes(3),
        ]);
        $space->tutorMessages()->create([
            'role' => 'assistant', 'content' => 'A Hausa reply from an earlier turn.', 'citations' => [],
            'supplementary_sections' => [], 'status' => 'completed', 'language' => 'ha',
            'created_at' => now()->subMinutes(2),
        ]);
        $space->tutorMessages()->create([
            'role' => 'assistant', 'content' => 'Idahun Yoruba iṣaaju.', 'citations' => [],
            'supplementary_sections' => [], 'status' => 'completed', 'language' => 'yo',
            'created_at' => now()->subMinute(),
        ]);

        $this->app->bind(Tutor::class, fn () => new class($chunk->id) implements Tutor
        {
            public function __construct(private int $chunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::assertSame('yo', $language);
                TestCase::assertSame(['Explain this history.', 'Idahun Yoruba iṣaaju.'], array_column($conversation, 'content'));

                return [
                    'answer' => 'Idahun tuntun ni ede Yoruba.',
                    'citations' => [['chunk_id' => $this->chunkId, 'excerpt' => 'united much of northern Nigeria']],
                    'supplementary' => [],
                    'insufficient' => false,
                ];
            }
        });

        $this->actingAs($space->user)
            ->post(route('tutor.ask', $space), ['question' => 'What did the caliphate change?'])
            ->assertRedirect();

        $answer = $space->tutorMessages()->where('role', 'assistant')->latest()->firstOrFail();
        $this->assertSame('yo', $answer->language);
        $this->assertSame('Idahun tuntun ni ede Yoruba.', $answer->content);
    }

    public function test_no_evidence_replies_use_the_selected_language_without_calling_the_tutor(): void
    {
        $expected = [
            'en' => ['I could not find enough detail in your study materials', 'The tutor could not find enough detail in your materials', 'A new tutor message follows.'],
            'ha' => ['Ban sami isasshen bayani', 'Mai koyarwa bai sami isasshen bayani', 'Ga sabon saƙo daga mai koyarwa a ƙasa.'],
            'yo' => ['Mi ò rí ìwífún tó tó', 'Olùkọ́ kò rí ìwífún tó tó', 'Ìfiránṣẹ́ tuntun láti ọ̀dọ̀ olùkọ́ wà ní ìsàlẹ̀.'],
            'ig' => ['Ahụghị m ozi zuru ezu', 'Onye nkuzi ahụghị ozi zuru ezu', 'Ozi ọhụrụ sitere n’aka onye nkuzi dị n’okpuru.'],
        ];
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::fail('No tutor request should be made when retrieval returns no evidence.');
            }
        });

        foreach ($expected as $language => [$content, $status, $replyReady]) {
            $space = LearningSpace::factory()->create(['preferred_language' => $language]);

            $response = $this->actingAs($space->user)
                ->from(route('study.show', $space))
                ->post(route('tutor.ask', $space), ['question' => 'Explain photosynthesis.']);

            $message = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
            $response->assertRedirect(route('study.show', $space).'#tutor-reply-status-'.$message->id)
                ->assertSessionHas('status', fn (string $value): bool => str_contains($value, $status))
                ->assertSessionHas('tutor_reply_id', $message->id);
            $this->assertSame($language, $message->language);
            $this->assertStringContainsString($content, $message->content);
            $this->assertTrue($message->insufficient_evidence);
            $this->assertSame([], $message->citations);
            $this->get(route('study.show', $space))->assertOk()->assertSee($replyReady);
        }
    }

    public function test_unsupported_saved_language_uses_english_for_no_evidence_copy_and_turn_tag(): void
    {
        $space = LearningSpace::factory()->create(['preferred_language' => 'xx']);
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                TestCase::fail('No tutor request should be made when retrieval returns no evidence.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Explain photosynthesis.'])->assertRedirect();

        $message = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('en', $message->language);
        $this->assertStringContainsString('I could not find enough detail', $message->content);
    }

    public function test_citation_failure_fallback_uses_selected_language_and_preserves_exact_source_citation(): void
    {
        $space = LearningSpace::factory()->create(['preferred_language' => 'ha']);
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $sourceText = 'Photosynthesis stores light energy in glucose. Keep this source wording exactly as written.';
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => $sourceText,
            'page' => 2,
            'locator' => ['page' => 2],
            'relevance_status' => 'related',
            'relevance_confidence' => 1,
        ]);
        $concept = $space->concepts()->create([
            'name' => 'Photosynthesis',
            'description' => 'Photosynthesis stores light energy in glucose.',
        ]);
        $concept->sources()->attach($chunk);
        $unrelated = $material->chunks()->create([
            'extraction_version' => 1,
            'content' => 'Respiration releases stored energy.',
            'page' => 3,
            'locator' => ['page' => 3],
            'relevance_status' => 'related',
            'relevance_confidence' => 1,
        ]);
        $otherConcept = $space->concepts()->create(['name' => 'Respiration', 'description' => 'Respiration releases energy.']);
        $otherConcept->sources()->attach($unrelated);
        $fakeTutor = new class($chunk->id) implements Tutor
        {
            public int $calls = 0;

            public function __construct(private int $chunkId) {}

            public function answer(string $question, array $chunks, ?string $language = null, array $conversation = []): array
            {
                $this->calls++;
                TestCase::assertSame('ha', $language);
                TestCase::assertSame([$this->chunkId], array_column($chunks, 'id'));

                throw new TutorException('The tutor returned an unresolved citation.');
            }
        };
        $this->app->instance(Tutor::class, $fakeTutor);

        $this->actingAs($space->user)->post(route('tutor.ask', $space), [
            'question' => 'Explain photosynthesis.',
            'concept_id' => $concept->id,
        ])->assertRedirect()->assertSessionHas('status', 'Ba a iya tabbatar da cikakkiyar amsa ba, saboda haka an nuna sassan tushe na wannan batu.');

        $message = $space->tutorMessages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame(1, $fakeTutor->calls);
        $this->assertSame('fallback', $message->status);
        $this->assertSame('ha', $message->language);
        $this->assertSame($concept->id, $message->concept_id);
        $this->assertStringContainsString('Ban iya tabbatar', $message->content);
        $this->assertStringNotContainsString($concept->description, $message->content);
        $this->assertSame([['chunk_id' => $chunk->id, 'excerpt' => $sourceText]], $message->citations);
        $this->assertNotContains($otherConcept->id, [$message->concept_id]);

        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Sassan tushe na batun da kuka zaɓa')
            ->assertSee($sourceText)
            ->assertSee('data-read-aloud-language="ha"', false);
    }
}
