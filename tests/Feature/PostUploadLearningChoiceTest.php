<?php

namespace Tests\Feature;

use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostUploadLearningChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_paper_shows_source_topics_and_concept_first_route_even_before_question_indexing(): void
    {
        [$user, $material] = $this->paper();
        $concept = $this->addConcept($material, 'Energy transfer', 1);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('How would you like to use this paper?')
            ->assertSee('Topics linked to this paper')
            ->assertSee('Energy transfer')
            ->assertSee('Page 1')
            ->assertSee('Learn these topics first')
            ->assertSee('?concept='.$concept->id.'#concept-'.$concept->id)
            ->assertSee('Questions have not been prepared yet.')
            ->assertSee('Find or review questions')
            ->assertSee('Help me choose')
            ->assertDontSee('Start exam practice');
    }

    public function test_ready_questions_without_related_concepts_offer_practice_without_inventing_a_study_route(): void
    {
        [$user, $material] = $this->paper();
        $this->addQuestion($material, 1, 'ready');

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Practise this paper')
            ->assertSee('1 ready to practise.')
            ->assertDontSee('Learn these topics first');

        $this->get(route('materials.show', ['material' => $material, 'learning_intent' => 'learn_first']))
            ->assertOk()
            ->assertSee('There are no related ideas ready to learn yet. You can practise the available questions instead.')
            ->assertSee('Practise this paper');
    }

    public function test_help_me_choose_gives_an_explainable_non_locking_suggestion(): void
    {
        [$user, $material] = $this->paper();
        $this->addConcept($material, 'Energy transfer', 1);
        $this->addQuestion($material, 1, 'ready');

        $this->actingAs($user)->get(route('materials.show', ['material' => $material, 'learning_intent' => 'unsure']))
            ->assertOk()
            ->assertSee('You have 1 related idea to learn first. You can also practise 1 question now if you prefer.')
            ->assertSee('Open your study path')
            ->assertSee('Practise this paper instead')
            ->assertSee('You do not need to study the topics first to practise.');
    }

    public function test_questions_needing_review_do_not_show_a_broken_exam_start(): void
    {
        [$user, $material] = $this->paper();
        $this->addQuestion($material, 1, 'needs_review');

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('None are ready yet. 1 question needs a check against the paper.')
            ->assertSee('Find or review questions')
            ->assertSee('This question is not available for practice until its wording and structure have been checked.')
            ->assertDontSee('Start exam practice');
    }

    public function test_ready_questions_remain_practiceable_while_other_questions_need_review(): void
    {
        [$user, $material] = $this->paper();
        $this->addQuestion($material, 1, 'ready');
        $held = $this->addQuestion($material, 2, 'needs_review');
        $held->update(['question_number' => '2', 'review_reason' => 'The answer choices need checking.']);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('1 ready to practise.')
            ->assertSee('Start exam practice')
            ->assertSee('Question 2')
            ->assertSee('The answer choices need checking.')
            ->assertSee('Check or correct this question')
            ->assertSee('Questions needing review stay out.');
    }

    public function test_topics_from_a_different_material_are_not_attributed_to_this_paper(): void
    {
        [$user, $material] = $this->paper();
        $otherMaterial = $material->learningSpace->materials()->create([
            'title' => 'Separate web development notes',
            'role' => 'study_notes',
            'private_source_path' => 'materials/test/web.png',
            'original_filename' => 'web.png',
            'mime_type' => 'image/png',
            'size_bytes' => 20,
            'status' => 'ready',
            'extraction_version' => 1,
        ]);
        $this->addConcept($otherMaterial, 'Web development', 1);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertDontSee('Web development')
            ->assertDontSee('Learn these topics first');
    }

    public function test_stale_extraction_sources_are_not_shown_as_current_topic_evidence(): void
    {
        [$user, $material] = $this->paper();
        $concept = $this->addConcept($material, 'Energy transfer', 1);
        $staleChunk = $material->chunks()->create([
            'extraction_version' => 0,
            'content' => 'Outdated source text must not be shown.',
            'page' => 99,
            'locator' => ['page' => 99],
            'relevance_status' => 'related',
        ]);
        $concept->sources()->attach($staleChunk);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Energy transfer')
            ->assertSee('Page 1')
            ->assertDontSee('Page 99')
            ->assertDontSee('Outdated source text must not be shown.');
    }

    public function test_learner_can_focus_the_guided_path_on_selected_paper_topics_without_skipping_foundations(): void
    {
        [$user, $material] = $this->paper();
        $foundation = $this->addConcept($material, 'Energy basics', 1);
        $selected = $this->addConcept($material, 'Conservation of energy', 2);
        $selected->prerequisites()->attach($foundation, ['source_supported' => true]);
        $unrelated = $this->addConcept($material, 'Waves', 3);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Choose topics to focus on in your study path')
            ->assertSee('name="focus_concepts[]" value="'.$selected->id.'"', false)
            ->assertSee('name="focus_material" value="'.$material->uuid.'"', false);

        $this->get(route('study.show', $material->learningSpace).'?'.http_build_query([
            'focus_material' => $material->uuid,
            'focus_concepts' => [$selected->id],
        ]))
            ->assertOk()
            ->assertSee('Your chosen topics from '.$material->title)
            ->assertSeeTextInOrder(['Your recommended next step', 'Energy basics'])
            ->assertSeeTextInOrder(['Energy basics', 'Conservation of energy', 'Waves'])
            ->assertSee('Your path keeps any required foundations first. All other topics remain available below.');
    }

    public function test_topic_focus_rejects_concepts_not_linked_to_the_selected_source_material(): void
    {
        [$user, $material] = $this->paper();
        $otherMaterial = $material->learningSpace->materials()->create([
            'title' => 'Separate notes', 'role' => 'study_notes', 'private_source_path' => 'materials/test/notes.pdf',
            'original_filename' => 'notes.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 20,
            'status' => 'ready', 'extraction_version' => 1,
        ]);
        $otherConcept = $this->addConcept($otherMaterial, 'Unrelated topic', 1);

        $this->actingAs($user)->get(route('study.show', $material->learningSpace).'?'.http_build_query([
            'focus_material' => $material->uuid,
            'focus_concepts' => [$otherConcept->id],
        ]))->assertNotFound();
    }

    public function test_mixed_paper_route_appears_only_when_another_paper_has_ready_questions(): void
    {
        [$user, $material] = $this->paper();
        $this->addQuestion($material, 1, 'ready');
        $otherPaper = $material->learningSpace->materials()->create([
            'title' => 'Another physics paper',
            'role' => 'past_paper',
            'private_source_path' => 'materials/test/physics-2.pdf',
            'original_filename' => 'physics-2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => 'ready',
            'extraction_version' => 1,
        ]);
        $this->addQuestion($otherPaper, 1, 'ready');

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Choose papers for mixed practice')
            ->assertSee(route('past-paper-mixed.index', $material->learningSpace));
    }

    public function test_only_the_material_owner_can_see_the_choice(): void
    {
        [, $material] = $this->paper();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('materials.show', $material))->assertForbidden();
    }

    public function test_non_past_paper_material_does_not_get_exam_choice_panel(): void
    {
        $space = LearningSpace::factory()->create();
        $material = $space->materials()->create([
            'title' => 'Study notes',
            'role' => 'study_notes',
            'private_source_path' => 'materials/test/notes.png',
            'original_filename' => 'notes.png',
            'mime_type' => 'image/png',
            'size_bytes' => 20,
            'status' => 'ready',
            'extraction_version' => 1,
        ]);

        $this->actingAs($space->user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertDontSee('How would you like to use this paper?');
    }

    public function test_choice_waits_until_paper_processing_is_complete(): void
    {
        $space = LearningSpace::factory()->create();
        $material = $space->materials()->create([
            'title' => 'Paper still being prepared',
            'role' => 'past_paper',
            'private_source_path' => 'materials/test/pending.pdf',
            'original_filename' => 'pending.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => 'processing',
            'extraction_version' => 1,
            'metadata' => ['processing_stage' => 'relevance'],
        ]);
        $space->materials()->create([
            'title' => 'Ready revision notes', 'role' => 'study_notes',
            'private_source_path' => 'materials/test/ready-notes.pdf', 'original_filename' => 'ready-notes.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'status' => 'ready', 'extraction_version' => 1,
        ]);

        $this->actingAs($space->user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('All pages have been extracted. Now we’re checking which pages match your learning goal.')
            ->assertSee('You can continue with 1 other ready material in this study space while this one is being prepared.')
            ->assertSee(route('materials.index').'#space-'.$space->id.'-materials')
            ->assertDontSee('How would you like to use this paper?');
    }

    public function test_failed_material_keeps_retry_and_other_ready_materials_available(): void
    {
        $space = LearningSpace::factory()->create();
        $failed = $space->materials()->create([
            'title' => 'Physics paper', 'role' => 'past_paper',
            'private_source_path' => 'materials/test/physics.pdf', 'original_filename' => 'physics.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'status' => 'failed',
            'extraction_version' => 1, 'failure_message' => 'The page could not be read.',
            'metadata' => ['failed_stage' => 'extraction'],
        ]);
        $space->materials()->create([
            'title' => 'Ready revision notes', 'role' => 'study_notes',
            'private_source_path' => 'materials/test/ready-notes.pdf', 'original_filename' => 'ready-notes.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'status' => 'ready', 'extraction_version' => 1,
        ]);

        $this->actingAs($space->user)->get(route('materials.show', $failed))
            ->assertOk()
            ->assertSee('The problem happened while reading your material.')
            ->assertSee(route('materials.download', $failed))
            ->assertSee('Retry processing')
            ->assertSee('You can keep studying from 1 other ready material in this study space while you retry this one.')
            ->assertSee(route('materials.index').'#space-'.$space->id.'-materials')
            ->assertDontSee('How would you like to use this paper?');
    }

    private function paper(): array
    {
        $space = LearningSpace::factory()->create();
        $material = $space->materials()->create([
            'title' => 'Physics practice paper',
            'role' => 'past_paper',
            'private_source_path' => 'materials/test/physics.pdf',
            'original_filename' => 'physics.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => 'ready',
            'extraction_version' => 1,
        ]);

        return [$space->user, $material];
    }

    private function addConcept(Material $material, string $name, int $page): Concept
    {
        $chunk = $material->chunks()->create([
            'extraction_version' => $material->extraction_version,
            'content' => $name.' source evidence.',
            'page' => $page,
            'locator' => ['page' => $page],
            'relevance_status' => 'related',
        ]);
        $concept = $material->learningSpace->concepts()->create([
            'name' => $name,
            'description' => 'A source-linked learning idea.',
            'difficulty' => 'introductory',
        ]);
        $concept->sources()->attach($chunk);

        return $concept;
    }

    private function addQuestion(Material $material, int $page, string $status): PastPaperQuestion
    {
        $chunk = $material->chunks()->create([
            'extraction_version' => $material->extraction_version,
            'content' => 'Which choice shows the answer?',
            'page' => $page,
            'locator' => ['page' => $page],
            'relevance_status' => 'related',
        ]);

        return PastPaperQuestion::query()->create([
            'material_id' => $material->id,
            'material_chunk_id' => $chunk->id,
            'extraction_version' => $material->extraction_version,
            'page' => $page,
            'question_number' => '1',
            'prompt' => 'Which choice shows the answer?',
            'question_type' => 'single_choice',
            'options' => [['id' => 'A', 'text' => 'First choice'], ['id' => 'B', 'text' => 'Second choice']],
            'source_text' => 'Which choice shows the answer? A. First choice B. Second choice',
            'expected_answer' => null,
            'answer_source' => 'not_detected',
            'review_status' => $status,
            'review_reason' => null,
            'confidence' => .95,
            'parser_version' => 'page-question-parser-v1',
        ]);
    }
}
