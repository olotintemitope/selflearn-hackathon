<?php

namespace Tests\Feature;

use App\Jobs\ProcessMaterial;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\User;
use App\Services\Learning\MaterialRelevanceException;
use App\Services\Learning\OpenAiMaterialRelevanceClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaterialRelevanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_include_an_uncertain_page_or_keep_it_separate(): void
    {
        Queue::fake();
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $maybe = $material->chunks()->create([
            'extraction_version' => 1, 'page' => 2, 'content' => 'A page that might help.', 'locator' => ['page' => 2],
            'relevance_status' => 'possibly_related', 'relevance_confidence' => .59,
            'relevance_reason' => 'It may support the goal, but needs confirmation.',
        ]);
        $separate = $material->chunks()->create([
            'extraction_version' => 1, 'page' => 3, 'content' => 'A different topic.', 'locator' => ['page' => 3],
            'relevance_status' => 'possibly_related', 'relevance_confidence' => .52,
            'relevance_reason' => 'The relationship is unclear.',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.relevance', $material), ['chunk_id' => $maybe->id, 'decision' => 'related'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('related', $maybe->refresh()->relevance_status);
        Queue::assertPushed(ProcessMaterial::class, fn ($job) => $job->materialId === $material->id && $job->reuseClassifiedChunks);

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.relevance', $material), ['chunk_id' => $separate->id, 'decision' => 'separate'])
            ->assertRedirect();
        $this->assertSame('separate', $separate->refresh()->relevance_status);
        Queue::assertPushed(ProcessMaterial::class, 1);
    }

    public function test_clock_page_requires_the_owner_to_confirm_every_detected_time_before_it_is_included(): void
    {
        Queue::fake();
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'page' => 1,
            'content' => 'What time is it?',
            'locator' => ['page' => 1, 'ocr_content' => 'What time is it?', 'clock_diagrams' => [
                'version' => 'local-clock-reader-v1',
                'status' => 'needs_learner_confirmation',
                'faces' => [
                    ['index' => 1, 'candidate_time' => '03:00', 'match_quality' => 'candidate', 'bounds' => []],
                    ['index' => 2, 'candidate_time' => null, 'match_quality' => 'ambiguous', 'bounds' => []],
                ],
            ]],
            'relevance_status' => 'possibly_related',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->from(route('materials.show', $material))
            ->post(route('materials.relevance', $material), [
                'chunk_id' => $chunk->id,
                'decision' => 'related',
                'clock_readings' => [1 => '03:00'],
            ])->assertSessionHasErrors('clock_readings.2');
        $this->assertSame('possibly_related', $chunk->refresh()->relevance_status);
        Queue::assertNothingPushed();

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.relevance', $material), [
                'chunk_id' => $chunk->id,
                'decision' => 'related',
                'clock_readings' => [1 => '03:00', 2 => '06:15'],
            ])->assertRedirect()->assertSessionHas('status');

        $chunk->refresh();
        $this->assertSame('related', $chunk->relevance_status);
        $this->assertSame('What time is it?', $chunk->locator['ocr_content']);
        $this->assertSame('learner_confirmed', $chunk->locator['clock_diagrams']['status']);
        $this->assertSame('06:15', $chunk->locator['clock_diagrams']['faces'][1]['confirmed_time']);
        $this->assertStringContainsString('Learner-confirmed local clock diagram readings (not Mathpix OCR)', $chunk->content);
        Queue::assertPushed(ProcessMaterial::class, 1);
    }

    public function test_material_owner_cannot_decide_for_another_materials_page(): void
    {
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $otherMaterial = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $foreignChunk = $otherMaterial->chunks()->create([
            'extraction_version' => 1, 'page' => 1, 'content' => 'Private.', 'locator' => ['page' => 1],
            'relevance_status' => 'possibly_related', 'relevance_reason' => 'Needs review.',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.relevance', $material), ['chunk_id' => $foreignChunk->id, 'decision' => 'related'])
            ->assertNotFound();
        $this->assertSame('possibly_related', $foreignChunk->refresh()->relevance_status);
    }

    public function test_another_learner_cannot_change_material_relevance(): void
    {
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'page' => 1, 'content' => 'Private.', 'locator' => ['page' => 1],
            'relevance_status' => 'possibly_related', 'relevance_reason' => 'Needs review.',
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('materials.relevance', $material), ['chunk_id' => $chunk->id, 'decision' => 'related'])
            ->assertForbidden();
        $this->assertSame('possibly_related', $chunk->refresh()->relevance_status);
    }

    public function test_owner_can_rebuild_a_missing_study_guide_without_reextracting_the_material(): void
    {
        Queue::fake();
        $material = Material::factory()->create(['status' => 'ready']);
        $material->chunks()->create([
            'extraction_version' => 1, 'page' => 1, 'content' => 'Checked clock readings.',
            'locator' => ['clock_diagrams' => ['status' => 'learner_confirmed']],
            'relevance_status' => 'related',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.rebuild-guide', $material))
            ->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(ProcessMaterial::class, fn ($job) => $job->materialId === $material->id && $job->reuseClassifiedChunks);
    }

    public function test_another_learner_cannot_rebuild_a_materials_guide(): void
    {
        Queue::fake();
        $material = Material::factory()->create(['status' => 'ready']);
        $material->chunks()->create([
            'extraction_version' => 1, 'page' => 1, 'content' => 'Private.', 'locator' => [],
            'relevance_status' => 'related',
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('materials.rebuild-guide', $material))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_owner_can_request_matching_for_a_pending_saved_page(): void
    {
        Queue::fake();
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 2]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'page' => 1, 'content' => 'Previously extracted page.', 'locator' => ['page' => 1],
            'relevance_status' => 'pending',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->post(route('materials.relevance', $material), ['chunk_id' => $chunk->id, 'decision' => 'recheck'])
            ->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(ProcessMaterial::class, fn ($job) => $job->materialId === $material->id && $job->reuseClassifiedChunks);
        $this->assertSame('pending', $chunk->refresh()->relevance_status);
    }

    public function test_classifier_uses_learning_goal_strict_schema_and_holds_low_confidence_related_as_ambiguous(): void
    {
        config(['services.openai.api_key' => 'test-token', 'services.openai.endpoint' => 'https://api.example.test/v1', 'services.openai.model' => 'test-model']);
        Http::fake(['api.example.test/*' => Http::response([
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['classifications' => [
                ['chunk_id' => 15, 'status' => 'related', 'confidence' => .63, 'reason' => 'The page may support the goal but its purpose is not clear.'],
            ]])]]]],
        ])]);
        $space = LearningSpace::factory()->create(['goal' => 'Understand clocks and elapsed time']);
        $classifier = app(OpenAiMaterialRelevanceClassifier::class);

        $result = $classifier->classify($space, [['id' => 15, 'page' => 4, 'content' => 'A source page.']], []);

        $this->assertSame('possibly_related', $result[0]['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/responses')
            && $request['text']['format']['strict'] === true
            && $request['text']['format']['schema']['properties']['classifications']['items']['properties']['chunk_id']['type'] === 'integer'
            && str_contains($request['input'][1]['content'], 'Understand clocks and elapsed time'));
    }

    public function test_classifier_rejects_unknown_source_ids_and_handles_provider_failure_safely(): void
    {
        config(['services.openai.api_key' => 'test-token', 'services.openai.endpoint' => 'https://api.example.test/v1', 'services.openai.model' => 'test-model']);
        $space = LearningSpace::factory()->create();
        $classifier = app(OpenAiMaterialRelevanceClassifier::class);
        Http::fakeSequence('api.example.test/*')->push([
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['classifications' => [
                ['chunk_id' => 999, 'status' => 'related', 'confidence' => .99, 'reason' => 'Invented source.'],
            ]])]]]],
        ])->push(['error' => ['message' => 'private payload']], 429)
            ->push(['error' => ['message' => 'private payload']], 429)
            ->push(['error' => ['message' => 'private payload']], 429);
        try {
            $classifier->classify($space, [['id' => 15, 'page' => 4, 'content' => 'A source page.']], []);
            $this->fail('Unknown source ids must be rejected.');
        } catch (MaterialRelevanceException $exception) {
            $this->assertSame('Material relevance output failed validation.', $exception->getMessage());
        }

        try {
            $classifier->classify($space, [['id' => 15, 'page' => 4, 'content' => 'A source page.']], []);
            $this->fail('Provider failures must fail closed.');
        } catch (MaterialRelevanceException $exception) {
            $this->assertStringContainsString('OpenAI returned HTTP 429 for material relevance matching', $exception->getMessage());
        }
    }
}
