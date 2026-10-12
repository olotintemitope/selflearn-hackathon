<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineStudyPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_learner_can_download_text_pack_without_quiz_keys_or_explanations(): void
    {
        $space = LearningSpace::factory()->create(['title' => 'Learn fractions']);
        $concept = $space->concepts()->create(['name' => 'Equivalent fractions', 'description' => 'Fractions can have equal value.']);
        $material = Material::factory()->for($space)->create(['title' => 'Math notes', 'status' => 'ready', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1, 'content' => "\\section*{Equivalent Fractions}\nMultiply the numerator and denominator by the same number.", 'page' => 2,
            'locator' => ['page' => 2], 'relevance_status' => 'related',
        ]);
        $concept->sources()->attach($chunk);
        $assessment = $space->assessments()->create(['concept_id' => $concept->id, 'prompt_version' => 'test-v1']);
        $assessment->questions()->create([
            'concept_id' => $concept->id, 'question_type' => 'multiple_choice',
            'prompt' => 'Which fraction equals one half?',
            'options' => [['id' => 'A', 'text' => '2/4', 'correct' => true], ['id' => 'B', 'text' => '2/3']],
            'correct_option_id' => 'A', 'explanation' => 'The numerator and denominator were both doubled.',
            'source_refs' => [], 'rubric' => ['private' => 'answer key'], 'solution_steps' => [['step' => 'private']],
        ]);
        $assessment->questions()->create([
            'concept_id' => $concept->id, 'question_type' => 'open_ended',
            'prompt' => 'Explain why the equivalent fractions match.', 'options' => [],
            'correct_option_id' => '', 'explanation' => 'Private marking notes.',
            'source_refs' => [], 'rubric' => ['private' => 'marking criteria'],
            'solution_steps' => [['step' => 'private']], 'max_points' => 5,
        ]);

        $this->actingAs($space->user)->get(route('offline.study-pack.show', $space))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('space.title', 'Learn fractions')
            ->assertJsonPath('concepts.0.name', 'Equivalent fractions')
            ->assertJsonPath('concepts.0.sources.0.page', 2)
            ->assertJsonPath('concepts.0.sources.0.excerpt', "\\section*{Equivalent Fractions}\nMultiply the numerator and denominator by the same number.")
            ->assertJsonPath('assessments.0.questions.0.prompt', 'Which fraction equals one half?')
            ->assertJsonPath('assessments.0.questions.1.prompt', 'Explain why the equivalent fractions match.')
            ->assertJsonPath('assessments.0.submit_url', route('assessments.submit', $assessment))
            ->assertJsonMissingPath('csrf_token')
            ->assertJsonMissingPath('assessments.0.questions.0.options.0.correct')
            ->assertJsonMissingPath('assessments.0.questions.0.correct_option_id')
            ->assertJsonMissingPath('assessments.0.questions.0.explanation')
            ->assertJsonMissingPath('assessments.0.questions.1.rubric')
            ->assertJsonMissingPath('assessments.0.questions.1.solution_steps');
    }

    public function test_offline_pack_endpoint_and_reader_are_scoped_and_excluded_from_other_learners(): void
    {
        $space = LearningSpace::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('offline.study-pack.show', $space))->assertForbidden();
        $this->actingAs($space->user)->get(route('study.show', $space))
            ->assertOk()
            ->assertSee('Download for offline study')
            ->assertSee(route('offline.study-pack.show', $space));
        $this->assertFileExists(public_path('offline-study.html'));
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('js/offline-study.js'));
        $this->assertFileExists(public_path('css/offline-study.css'));
        $serviceWorker = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('if (SHELL_FILES.includes(url.pathname))', $serviceWorker);
        $this->assertStringContainsString('caches.match(request).then((cached) => cached || fetch(request))', $serviceWorker);
    }

    public function test_sync_context_is_authenticated_and_returns_no_store_owner_context(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('offline.sync-context'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('user_id', (string) $user->getAuthIdentifier())
            ->assertJsonStructure(['csrf_token']);

        auth()->logout();
        $this->getJson(route('offline.sync-context'))->assertUnauthorized();
    }
}
