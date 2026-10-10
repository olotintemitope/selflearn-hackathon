<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\MaterialChunk;
use App\Models\User;
use App\Services\Learning\Tutor;
use App\Services\Learning\TutorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyPathTutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_path_is_deterministic_and_orders_prerequisites_first(): void
    {
        $space = LearningSpace::factory()->create();
        $advanced = $space->concepts()->create(['name' => 'Applications', 'description' => 'Use the idea.']);
        $foundation = $space->concepts()->create(['name' => 'Basics', 'description' => 'Learn the idea.']);
        $advanced->prerequisites()->attach($foundation, ['source_supported' => true]);

        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSeeTextInOrder(['Basics', 'Applications']);
        $this->actingAs($space->user)->get(route('study.show', $space))->assertOk();

        $this->assertDatabaseCount('learning_paths', 1);
        $this->assertSame([$foundation->id, $advanced->id], $space->learningPaths()->first()->items()->pluck('concept_id')->all());
    }

    public function test_another_learner_cannot_view_a_path_or_ask_its_tutor(): void
    {
        $space = LearningSpace::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('study.show', $space))->assertForbidden();
        $this->actingAs($other)->post(route('tutor.ask', $space), ['question' => 'Explain this'])->assertForbidden();
    }

    public function test_tutor_answer_persists_resolvable_citations_from_only_the_owned_space(): void
    {
        [$space, $chunk] = $this->readyChunk('Photosynthesis stores light energy as chemical energy.');
        $otherChunk = $this->readyChunk('Photosynthesis is discussed elsewhere.')[1];
        $this->app->bind(Tutor::class, fn () => new class($chunk->id, $otherChunk->id) implements Tutor
        {
            public function __construct(private int $ownedId, private int $otherId) {}

            public function answer(string $question, array $chunks, ?string $language = null): array
            {
                TestCase::assertSame([$this->ownedId], array_column($chunks, 'id'));
                TestCase::assertNotContains($this->otherId, array_column($chunks, 'id'));

                return [
                    'answer' => 'It stores light as chemical energy.',
                    'citations' => [['chunk_id' => $this->ownedId, 'excerpt' => 'stores light energy as chemical energy']],
                    'supplementary' => [], 'insufficient' => false,
                ];
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'How does photosynthesis store energy?'])
            ->assertRedirect();
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSee('It stores light as chemical energy.')->assertSee('Page 1');
        $this->assertDatabaseHas('tutor_messages', ['learning_space_id' => $space->id, 'role' => 'assistant', 'status' => 'completed']);
    }

    public function test_missing_retrieval_evidence_returns_explicit_response_without_calling_provider(): void
    {
        [$space] = $this->readyChunk('Mitochondria produce cellular energy.');
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null): array
            {
                throw new \LogicException('Provider must not be called without evidence.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Who wrote Hamlet?'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('tutor_messages', [
            'learning_space_id' => $space->id,
            'insufficient_evidence' => true,
            'content' => 'I do not have enough evidence in your uploaded materials to answer that question.',
        ]);
    }

    public function test_provider_timeout_is_visible_and_does_not_expose_provider_payloads(): void
    {
        [$space] = $this->readyChunk('Gravity attracts masses toward each other.');
        $this->app->bind(Tutor::class, fn () => new class implements Tutor
        {
            public function answer(string $question, array $chunks, ?string $language = null): array
            {
                throw new TutorException('The tutor timed out. Please try again.');
            }
        });

        $this->actingAs($space->user)->post(route('tutor.ask', $space), ['question' => 'Explain gravity'])->assertRedirect();
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()->assertSee('The tutor timed out. Please try again.');
        $this->assertDatabaseHas('tutor_messages', ['learning_space_id' => $space->id, 'status' => 'failed']);
    }

    /** @return array{LearningSpace, MaterialChunk} */
    private function readyChunk(string $content): array
    {
        $space = LearningSpace::factory()->create();
        $material = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'content' => $content, 'page' => 1, 'locator' => ['page' => 1],
        ]);

        return [$space, $chunk];
    }
}
