<?php

namespace Tests\Feature;

use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperMarkingSchemeMatch;
use App\Models\PastPaperQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PastPaperMarkingSchemeMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_confirm_a_ready_answer_key_for_one_exact_paper_without_scoring_questions(): void
    {
        [$owner, $space, $paper] = $this->paper();
        $scheme = $this->material($space, 'Physics answers', 'marking_scheme', 3);
        $question = $this->question($paper);

        $this->actingAs($owner)->get(route('materials.show', $paper))
            ->assertOk()
            ->assertSee('Answer key for this paper')
            ->assertSee('Linking files alone does not verify answers or change practice scores.')
            ->assertSee('I confirm this answer key belongs to this exact paper.')
            ->assertSee('Physics answers');

        $this->from(route('materials.show', $paper))->post(route('materials.marking-schemes.match', $paper), [
            'marking_scheme_uuid' => $scheme->uuid,
            'confirm_match' => '1',
        ])->assertRedirect(route('materials.show', $paper));

        $match = PastPaperMarkingSchemeMatch::query()->sole();
        $this->assertSame($paper->id, $match->paper_material_id);
        $this->assertSame($scheme->id, $match->marking_scheme_material_id);
        $this->assertSame($owner->id, $match->confirmed_by);
        $this->assertSame($paper->extraction_version, $match->paper_extraction_version);
        $this->assertSame($scheme->extraction_version, $match->marking_scheme_extraction_version);
        $this->assertSame('Which choice describes the principle?', $question->fresh()->prompt);
        $this->assertNull($question->fresh()->expected_answer);
        $this->assertSame('not_detected', $question->fresh()->answer_source);
        $this->assertSame('ready', $question->fresh()->review_status);
        $this->assertSame(0, $question->attempts()->count());

        $this->get(route('materials.show', $paper))
            ->assertOk()
            ->assertSee('Physics answers')
            ->assertSee('Check each question against both originals before its answer can affect future scores.');
    }

    public function test_other_learners_cannot_view_or_link_a_private_paper_or_its_answer_key(): void
    {
        [$owner, , $paper] = $this->paper();
        $scheme = $this->material($paper->learningSpace, 'Private answers', 'marking_scheme', 1);
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('materials.show', $paper))->assertForbidden();
        $this->post(route('materials.marking-schemes.match', $paper), [
            'marking_scheme_uuid' => $scheme->uuid,
            'confirm_match' => '1',
        ])->assertForbidden();

        $this->assertSame(0, PastPaperMarkingSchemeMatch::query()->count());
    }

    public function test_only_a_ready_marking_scheme_from_the_same_space_can_be_linked(): void
    {
        [$owner, $space, $paper] = $this->paper();
        $otherSpace = LearningSpace::factory()->for($owner)->create();
        $foreignScheme = $this->material($otherSpace, 'Other space answers', 'marking_scheme', 1);
        $notes = $this->material($space, 'Study notes', 'study_notes', 1);
        $pendingScheme = $this->material($space, 'Not ready answers', 'marking_scheme', 0, 'processing');

        foreach ([$foreignScheme, $notes, $pendingScheme] as $candidate) {
            $this->actingAs($owner)->from(route('materials.show', $paper))
                ->post(route('materials.marking-schemes.match', $paper), [
                    'marking_scheme_uuid' => $candidate->uuid,
                    'confirm_match' => '1',
                ])
                ->assertSessionHasErrors('marking_scheme_uuid');
        }

        $this->assertSame(0, PastPaperMarkingSchemeMatch::query()->count());
    }

    public function test_confirmation_is_required_and_a_link_is_tied_to_the_extraction_versions(): void
    {
        [$owner, $space, $paper] = $this->paper();
        $scheme = $this->material($space, 'Checked answers', 'marking_scheme', 2);

        $this->actingAs($owner)->from(route('materials.show', $paper))
            ->post(route('materials.marking-schemes.match', $paper), ['marking_scheme_uuid' => $scheme->uuid])
            ->assertSessionHasErrors('confirm_match');
        $this->assertSame(0, PastPaperMarkingSchemeMatch::query()->count());

        $this->post(route('materials.marking-schemes.match', $paper), [
            'marking_scheme_uuid' => $scheme->uuid,
            'confirm_match' => '1',
        ])->assertRedirect();
        $firstMatch = PastPaperMarkingSchemeMatch::query()->sole();

        $paper->update(['extraction_version' => 2]);
        $this->get(route('materials.show', $paper->fresh()))
            ->assertOk()
            ->assertDontSee('You linked this answer key to this paper. Questions have not been checked against it yet.');
        $this->post(route('materials.marking-schemes.match', $paper->fresh()), [
            'marking_scheme_uuid' => $scheme->uuid,
            'confirm_match' => '1',
        ])->assertRedirect();

        $this->assertSame(2, PastPaperMarkingSchemeMatch::query()->count());
        $this->assertSame(1, $firstMatch->fresh()->paper_extraction_version);
        $this->assertSame(2, PastPaperMarkingSchemeMatch::query()->latest('id')->value('paper_extraction_version'));
    }

    private function paper(): array
    {
        $owner = User::factory()->create();
        $space = LearningSpace::factory()->for($owner)->create();
        $paper = $this->material($space, 'Physics paper 2025', 'past_paper', 1);

        return [$owner, $space, $paper];
    }

    private function material(LearningSpace $space, string $title, string $role, int $version, string $status = 'ready'): Material
    {
        return Material::factory()->for($space)->create([
            'title' => $title,
            'role' => $role,
            'status' => $status,
            'extraction_version' => $version,
        ]);
    }

    private function question(Material $paper): PastPaperQuestion
    {
        $chunk = $paper->chunks()->create([
            'page' => 2,
            'content' => 'Which choice describes the principle?',
            'locator' => ['page' => 2],
            'extraction_version' => $paper->extraction_version,
            'relevance_status' => 'related',
        ]);

        return $paper->pastPaperQuestions()->create([
            'material_chunk_id' => $chunk->id,
            'extraction_version' => $paper->extraction_version,
            'page' => 2,
            'question_number' => '1',
            'prompt' => 'Which choice describes the principle?',
            'question_type' => 'single_choice',
            'options' => [
                ['id' => 'A', 'text' => 'First choice'],
                ['id' => 'B', 'text' => 'Second choice'],
            ],
            'source_text' => '1. Which choice describes the principle? A. First choice B. Second choice',
            'expected_answer' => null,
            'answer_source' => 'not_detected',
            'review_status' => 'ready',
            'confidence' => 0.99,
            'parser_version' => 'test-parser-v1',
        ]);
    }
}
