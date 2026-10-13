<?php

namespace Tests\Unit;

use App\Models\LearningSpace;
use App\Services\Learning\LocalMaterialRelevanceClassifier;
use App\Services\Learning\MaterialRelevanceClassifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalMaterialRelevanceClassifierTest extends TestCase
{
    public function test_it_matches_related_history_pages_and_separates_web_development(): void
    {
        Http::fake();
        $space = new LearningSpace(['title' => 'Nigerian history', 'goal' => 'Understand Nigerian history']);
        $classifier = app(MaterialRelevanceClassifier::class);

        $result = $classifier->classify($space, [
            ['id' => 11, 'page' => 1, 'content' => 'Nigeria: Historical Setting. The history of Nigeria begins with early kingdoms.'],
            ['id' => 12, 'page' => 2, 'content' => 'HTML and CSS structure and style a responsive web page.'],
        ], []);

        $this->assertSame(['related', 'separate'], array_column($result, 'status'));
        $this->assertSame([11, 12], array_column($result, 'chunk_id'));
        $this->assertStringContainsString('nigeria', $result[0]['reason']);
        Http::assertNothingSent();
    }

    public function test_it_leaves_a_single_term_match_for_learner_review(): void
    {
        $space = new LearningSpace(['title' => 'AI and machine learning', 'goal' => 'Learn artificial intelligence and machine learning']);
        $classifier = new LocalMaterialRelevanceClassifier;

        $result = $classifier->classify($space, [
            ['id' => 21, 'page' => 3, 'content' => 'This page uses intelligence to explain an unrelated topic.'],
        ], []);

        $this->assertSame('possibly_related', $result[0]['status']);
    }

    public function test_a_generic_space_uses_its_existing_topic_to_keep_other_domains_out(): void
    {
        Http::fake();
        $space = new LearningSpace([
            'title' => 'Real scan batch evaluation',
            'goal' => 'Study the materials I upload',
        ]);
        $existingConcepts = [
            [
                'name' => 'Conservation of energy',
                'description' => 'Energy changes form in a closed system. A pendulum transfers potential energy into kinetic energy. A force changes motion.',
            ],
            [
                'name' => 'Work and power',
                'description' => 'Work transfers energy when a force moves an object. Power describes the rate of energy transfer.',
            ],
        ];
        $classifier = new LocalMaterialRelevanceClassifier;

        $result = $classifier->classify($space, [
            ['id' => 41, 'page' => 1, 'content' => 'Food and Nutrition: carbohydrates provide nutrients. Proteins support growth, while vitamins and minerals support health.'],
            ['id' => 42, 'page' => 2, 'content' => 'Information and Communication Technology: computer hardware, software, networks, and web pages.'],
            ['id' => 43, 'page' => 3, 'content' => 'Conservation of energy explains energy changes. Work and power describe energy transfer.'],
        ], $existingConcepts);

        $this->assertSame(['separate', 'separate', 'related'], array_column($result, 'status'));
        $this->assertStringContainsString('different topic', $result[0]['reason']);
        $this->assertStringContainsString('study path', $result[2]['reason']);
        Http::assertNothingSent();
    }

    public function test_a_broad_space_holds_a_page_that_only_shares_general_words_with_source_topics(): void
    {
        $space = new LearningSpace([
            'title' => 'General study',
            'goal' => 'Study materials I upload',
        ]);
        $existingConcepts = [
            ['name' => 'Heat-induced change of state', 'description' => 'Heat can change the state of a substance.'],
            ['name' => 'Thermal expansion', 'description' => 'Materials expand when temperature rises.'],
            ['name' => 'Anomalous expansion of water', 'description' => 'Water expands unusually near freezing.'],
            ['name' => 'Latent heat', 'description' => 'Heat changes state without a temperature change.'],
        ];
        $classifier = new LocalMaterialRelevanceClassifier;

        $result = $classifier->classify($space, [[
            'id' => 61,
            'page' => 1,
            'content' => 'Food hygiene: use clean water, keep food at a safe temperature, and heat food thoroughly.',
        ]], $existingConcepts);

        $this->assertSame('possibly_related', $result[0]['status']);
        $this->assertStringContainsString('connection is not clear', $result[0]['reason']);
    }

    public function test_a_broad_study_space_can_learn_its_first_topic_without_manual_subject_selection(): void
    {
        $space = new LearningSpace(['title' => 'General study', 'goal' => 'Study materials I upload']);
        $classifier = new LocalMaterialRelevanceClassifier;

        $this->assertTrue($classifier->hasBroadGoal($space));
        $result = $classifier->classify($space, [
            ['id' => 51, 'page' => 1, 'content' => 'The law of conservation of energy describes how energy changes form.'],
            ['id' => 52, 'page' => 2, 'content' => '---'],
        ], []);

        $this->assertSame(['related', 'possibly_related'], array_column($result, 'status'));
        $this->assertStringContainsString('first clear material', $result[0]['reason']);
    }

    public function test_default_my_study_space_is_treated_as_a_broad_goal(): void
    {
        $space = new LearningSpace(['title' => 'My study space']);

        $this->assertTrue((new LocalMaterialRelevanceClassifier)->hasBroadGoal($space));
    }

    public function test_a_single_specific_topic_keeps_a_goal_out_of_broad_space_bootstrapping(): void
    {
        $space = new LearningSpace(['title' => 'Physics', 'goal' => '']);
        $classifier = new LocalMaterialRelevanceClassifier;

        $this->assertFalse($classifier->hasBroadGoal($space));
        $result = $classifier->classify($space, [
            ['id' => 53, 'page' => 1, 'content' => 'Physics explains motion and forces.'],
        ], []);

        $this->assertSame('related', $result[0]['status']);
    }

    public function test_it_does_not_claim_a_match_when_goal_or_page_text_is_empty(): void
    {
        $classifier = new LocalMaterialRelevanceClassifier;
        $space = new LearningSpace(['title' => 'General study', 'goal' => '']);

        $result = $classifier->classify($space, [['id' => 31, 'page' => 1, 'content' => '']], []);

        $this->assertSame('possibly_related', $result[0]['status']);
        $this->assertSame(.5, $result[0]['confidence']);
    }
}
