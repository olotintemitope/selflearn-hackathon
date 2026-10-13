<?php

namespace Tests\Feature;

use App\Models\Concept;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperPracticeRun;
use App\Models\PastPaperQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MixedPastPaperPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_select_two_papers_and_source_identity_survives_practice_and_results(): void
    {
        [$user, $space, $firstPaper, $firstQuestion] = $this->paper('Physics 2021', 2021, 1);
        $firstSecondQuestion = $this->question($firstPaper, 2, 4, ['question_number' => '2', 'marks' => null]);
        $secondPaper = $this->addPaper($space, 'Physics 2022', 0);
        $secondQuestion = $this->question($secondPaper, 1, 1, ['paper_year' => 2022, 'marks' => 6]);
        $this->addConcept($firstPaper, 'Energy transfer', 2);
        $chemicalConcept = $this->addConcept($secondPaper, 'Chemical reactions', 3);
        $staleChunk = $secondPaper->chunks()->create([
            'extraction_version' => 0,
            'content' => 'Outdated text must not appear as a current topic.',
            'page' => 99,
            'locator' => ['page' => 99],
            'relevance_status' => 'related',
        ]);
        $chemicalConcept->sources()->attach($staleChunk);
        $excluded = $this->question($secondPaper, 3, 3, [
            'question_number' => '3', 'review_status' => 'needs_review',
        ]);

        $this->actingAs($user)->get(route('past-paper-mixed.index', $space))
            ->assertOk()
            ->assertSee('Physics 2021')
            ->assertSee('Physics 2022')
            ->assertSee('Year 2021 from the paper title or file name')
            ->assertSee('2 clear questions ready')
            ->assertSeeInOrder(['Physics 2021', 'Energy transfer', 'Page 2', 'Physics 2022', 'Chemical reactions', 'Page 3'])
            ->assertSee('I checked the topics and want these papers in one practice set, even if the topics differ.')
            ->assertDontSee('Page 99')
            ->assertDontSee('Outdated text must not appear as a current topic.')
            ->assertDontSee($excluded->prompt);

        $this->post(route('past-paper-mixed.start', $space), [
            'material_ids' => [$secondPaper->id, $firstPaper->id],
            'duration_minutes' => 60,
            'topic_grouping_confirmed' => '1',
        ])->assertRedirect();

        $run = PastPaperPracticeRun::query()->firstOrFail();
        $this->assertSame([$secondPaper->id, $firstPaper->id], $run->selected_material_ids);
        $this->assertSame([$secondQuestion->id, $firstQuestion->id, $firstSecondQuestion->id], $run->question_ids);
        $this->assertSame(60, $run->duration_minutes);
        $this->assertTrue($run->expires_at->equalTo($run->started_at->copy()->addMinutes(60)));

        $this->get(route('past-paper-practice.show', [$secondPaper, $run]))
            ->assertOk()
            ->assertSee('Mixed-paper practice')
            ->assertSee('Physics 2021 · Question 1 · Page 1')
            ->assertSee('Physics 2022 · Question 1 · Page 1')
            ->assertSee('Year 2022 from the paper title or file name, not checked against official records.')
            ->assertSee('Practice timer, not official paper timing')
            ->assertDontSee('A is the answer')
            ->assertDontSee('total marks');

        $this->assertNotContains($excluded->id, $run->question_ids);

        $this->post(route('past-paper-practice.submit', [$secondPaper, $run]), [
            'answers' => [
                $secondQuestion->id => ['value' => 'A'],
                $firstQuestion->id => ['value' => 'B'],
            ],
        ])->assertRedirect(route('past-paper-practice.results', $run));

        $this->get(route('past-paper-practice.results', $run))
            ->assertOk()
            ->assertSee('Your mixed-paper practice')
            ->assertSee('Physics 2021 · Question 1 · Page 1')
            ->assertSee('Physics 2022 · Question 1 · Page 1')
            ->assertSee('Year 2022 from the paper title or file name, not checked against official records.')
            ->assertSee('Open this original paper')
            ->assertSee('A is the answer');

        $attemptQuestionIds = $run->attempts()->pluck('past_paper_question_id')->sort()->values()->all();
        $this->assertSame(collect([$firstQuestion->id, $secondQuestion->id])->sort()->values()->all(), $attemptQuestionIds);
    }

    public function test_mixed_practice_cannot_start_until_the_learner_confirms_the_selected_papers(): void
    {
        [$user, $space, $firstPaper] = $this->paper('Physics 2021', 2021, 1);
        $secondPaper = $this->addPaper($space, 'Physics 2022', 1);

        $response = $this->actingAs($user)->from(route('past-paper-mixed.index', $space))->followingRedirects()
            ->post(route('past-paper-mixed.start', $space), [
                'material_ids' => [$firstPaper->id, $secondPaper->id],
                'duration_minutes' => 30,
            ]);

        $response->assertOk()
            ->assertSee('Confirm that you want these selected papers in the same practice set.');

        $this->assertSame(0, PastPaperPracticeRun::query()->count());
    }

    public function test_mixed_selection_rejects_materials_from_other_spaces_and_other_owners(): void
    {
        [$user, $space, $firstPaper] = $this->paper('Chemistry 2020', 2020, 1);
        $sameOwnerOtherSpace = $this->addPaper(LearningSpace::factory()->for($user)->create(), 'Other subject 2020', 1);
        $otherOwnerPaper = $this->addPaper(LearningSpace::factory()->create(), 'Private paper 2022', 1);

        $this->actingAs($user)->from(route('past-paper-mixed.index', $space))
            ->post(route('past-paper-mixed.start', $space), [
                'material_ids' => [$firstPaper->id, $sameOwnerOtherSpace->id],
                'duration_minutes' => 30,
                'topic_grouping_confirmed' => '1',
            ])
            ->assertSessionHasErrors('material_ids');

        $this->post(route('past-paper-mixed.start', $space), [
            'material_ids' => [$firstPaper->id, $otherOwnerPaper->id],
            'duration_minutes' => 30,
            'topic_grouping_confirmed' => '1',
        ])->assertSessionHasErrors('material_ids');

        $this->assertSame(0, PastPaperPracticeRun::query()->count());
    }

    public function test_each_selected_paper_must_have_current_clear_questions_and_invalid_timing_is_rejected(): void
    {
        [$user, $space, $firstPaper] = $this->paper('History 2019', 2019, 1);
        $emptyPaper = $this->addPaper($space, 'History 2020', 0);

        $this->actingAs($user)->from(route('past-paper-mixed.index', $space))
            ->post(route('past-paper-mixed.start', $space), [
                'material_ids' => [$firstPaper->id, $emptyPaper->id],
                'duration_minutes' => 30,
                'topic_grouping_confirmed' => '1',
            ])
            ->assertSessionHasErrors('material_ids');

        $secondPaper = $this->addPaper($space, 'History 2020', 1);
        $this->post(route('past-paper-mixed.start', $space), [
            'material_ids' => [$firstPaper->id, $secondPaper->id],
            'duration_minutes' => 45,
            'topic_grouping_confirmed' => '1',
        ])->assertSessionHasErrors('duration_minutes');

        $this->assertSame(0, PastPaperPracticeRun::query()->count());
    }

    public function test_mixed_selector_and_run_are_private_to_the_learning_space_owner(): void
    {
        [$owner, $space, $firstPaper] = $this->paper('Biology 2021', 2021, 1);
        $secondPaper = $this->addPaper($space, 'Biology 2022', 1);
        $this->actingAs($owner)->post(route('past-paper-mixed.start', $space), [
            'material_ids' => [$firstPaper->id, $secondPaper->id],
            'duration_minutes' => 30,
            'topic_grouping_confirmed' => '1',
        ]);
        $run = PastPaperPracticeRun::query()->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('past-paper-mixed.index', $space))->assertForbidden();
        $this->get(route('past-paper-practice.show', [$firstPaper, $run]))->assertForbidden();
        $this->get(route('past-paper-practice.results', $run))->assertForbidden();
    }

    public function test_reextracting_any_selected_paper_does_not_retarget_a_mixed_run(): void
    {
        [$user, $space, $firstPaper] = $this->paper('Geography 2020', 2020, 1);
        $secondPaper = $this->addPaper($space, 'Geography 2021', 1);
        $this->actingAs($user)->post(route('past-paper-mixed.start', $space), [
            'material_ids' => [$firstPaper->id, $secondPaper->id],
            'duration_minutes' => 30,
            'topic_grouping_confirmed' => '1',
        ]);
        $run = PastPaperPracticeRun::query()->firstOrFail();

        $secondPaper->update(['extraction_version' => 2]);

        $this->get(route('past-paper-practice.show', [$firstPaper, $run]))->assertStatus(409);
        $this->assertNull($run->fresh()->submitted_at);
        $this->assertSame(0, $run->attempts()->count());
    }

    private function paper(string $title, int $year, int $pageCount): array
    {
        $user = User::factory()->create();
        $space = LearningSpace::factory()->for($user)->create();
        $paper = $this->addPaper($space, $title, 0);
        $question = $pageCount > 0 ? $this->question($paper, 1, 1, ['paper_year' => $year]) : null;

        return [$user, $space, $paper, $question];
    }

    private function addPaper(LearningSpace $space, string $title, int $questionCount): Material
    {
        $paper = Material::factory()->for($space)->create([
            'title' => $title,
            'role' => 'past_paper',
            'status' => 'ready',
            'extraction_version' => 1,
        ]);

        for ($number = 1; $number <= $questionCount; $number++) {
            $this->question($paper, $number, $number);
        }

        return $paper;
    }

    private function addConcept(Material $paper, string $name, int $page): Concept
    {
        $chunk = $paper->chunks()->create([
            'extraction_version' => $paper->extraction_version,
            'content' => $name.' source evidence.',
            'page' => $page,
            'locator' => ['page' => $page],
            'relevance_status' => 'related',
        ]);
        $concept = $paper->learningSpace->concepts()->create([
            'name' => $name,
            'description' => 'A source-linked topic.',
            'difficulty' => 'introductory',
        ]);
        $concept->sources()->attach($chunk);

        return $concept;
    }

    private function question(Material $paper, int $number, int $page, array $overrides = []): PastPaperQuestion
    {
        $chunk = $paper->chunks()->create([
            'extraction_version' => 1,
            'content' => "Question {$number}. Which option is correct?\nEXPECTED RESPONSES:\nA is the answer",
            'page' => $page,
            'locator' => ['page' => $page, 'extractor' => 'pdftotext', 'confidence' => .97],
            'relevance_status' => 'related',
        ]);

        return PastPaperQuestion::query()->create(array_merge([
            'material_id' => $paper->id,
            'material_chunk_id' => $chunk->id,
            'extraction_version' => 1,
            'page' => $page,
            'question_number' => (string) $number,
            'prompt' => "Which option is correct in paper {$paper->title}?",
            'question_type' => 'single_choice',
            'options' => [['id' => 'A', 'text' => 'First choice'], ['id' => 'B', 'text' => 'Second choice']],
            'source_text' => 'Which option is correct? A. First choice B. Second choice',
            'expected_answer' => 'A',
            'answer_source' => 'source_expected_response',
            'review_status' => 'ready',
            'review_reason' => null,
            'confidence' => .97,
            'parser_version' => 'page-question-parser-v1',
            'paper_year' => null,
            'marks' => 2,
        ], $overrides));
    }
}
