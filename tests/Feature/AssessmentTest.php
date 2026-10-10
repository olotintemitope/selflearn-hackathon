<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\User;
use App\Services\Learning\AssessmentProgress;
use App\Services\Learning\QuizGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_can_generate_take_and_idempotently_retry_source_grounded_practice(): void
    {
        [$user, $space, $concept] = $this->spaceWithSourceConcept();
        $this->bindFakeGenerator();

        $this->actingAs($user)->post(route('assessments.store', [$space, $concept]))
            ->assertRedirect();
        $assessment = Assessment::with('questions')->firstOrFail();
        $this->assertTrue(Str::isUuid($assessment->id));
        $this->assertCount(3, $assessment->questions);
        foreach ($assessment->questions as $question) {
            $this->assertTrue(Str::isUuid($question->id));
        }

        $this->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Submit answers')
            ->assertDontSee('Correct answer')
            ->assertDontSee('correct_option_id');

        $uuid = '35197cb9-bbc8-4d7d-a4cb-69db9335a95d';
        $wrongAnswers = $assessment->questions->mapWithKeys(fn ($question) => [$question->id => 'B'])->all();
        $this->post(route('assessments.submit', $assessment), ['attempt_uuid' => $uuid, 'answers' => $wrongAnswers])
            ->assertRedirect();
        $attempt = $assessment->attempts()->firstOrFail();
        $this->assertTrue(Str::isUuid($attempt->id));
        $this->assertSame(0, $attempt->correct_count);
        $this->assertDatabaseCount('assessment_answers', 3);
        foreach ($attempt->answers as $answer) {
            $this->assertTrue(Str::isUuid($answer->id));
        }

        $this->get(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]))
            ->assertOk()
            ->assertSee('0 of 3 correct')
            ->assertSee('A useful next step')
            ->assertSee('Foundations')
            ->assertSee('has not been assessed yet');

        $correctAnswers = $assessment->questions->mapWithKeys(fn ($question) => [$question->id => 'A'])->all();
        $this->post(route('assessments.submit', $assessment), ['attempt_uuid' => $uuid, 'answers' => $correctAnswers])
            ->assertRedirect(route('assessments.show', ['assessment' => $assessment, 'attempt' => $attempt->id]));
        $this->assertDatabaseCount('assessment_attempts', 1);
        $this->assertDatabaseHas('assessment_attempts', ['id' => $attempt->id, 'correct_count' => 0]);
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

    public function test_revision_advisor_selects_the_lowest_measured_prerequisite(): void
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $strong = $space->concepts()->create(['name' => 'Strong foundation', 'description' => 'Known well.']);
        $weak = $space->concepts()->create(['name' => 'Weak foundation', 'description' => 'Needs review.']);
        $target = $space->concepts()->create(['name' => 'Dependent concept', 'description' => 'Builds on both.']);
        $target->prerequisites()->attach([$strong->id, $weak->id]);

        foreach ([[$strong, true], [$weak, false]] as [$concept, $correct]) {
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

        return [$user, $space, $concept];
    }

    private function bindFakeGenerator(): void
    {
        $this->app->instance(QuizGenerator::class, new class implements QuizGenerator
        {
            public function generate(string $conceptName, array $evidence, int $count = 3): array
            {
                return array_fill(0, $count, [
                    'prompt' => 'Which process is supported by the source about '.$conceptName.'?',
                    'options' => [
                        ['id' => 'A', 'text' => 'Plants use light energy to form glucose.'],
                        ['id' => 'B', 'text' => 'Plants use moonlight to form proteins.'],
                        ['id' => 'C', 'text' => 'Plants convert oxygen into sunlight.'],
                        ['id' => 'D', 'text' => 'Plants obtain glucose from soil minerals.'],
                    ],
                    'correct_option_id' => 'A',
                    'explanation' => 'The cited source says plants use light energy to make glucose.',
                    'source_chunk_ids' => [$evidence[0]['id']],
                ]);
            }
        });
    }
}
