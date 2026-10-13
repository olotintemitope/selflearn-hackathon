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

    public function test_it_does_not_claim_a_match_when_goal_or_page_text_is_empty(): void
    {
        $classifier = new LocalMaterialRelevanceClassifier;
        $space = new LearningSpace(['title' => 'General study', 'goal' => '']);

        $result = $classifier->classify($space, [['id' => 31, 'page' => 1, 'content' => '']], []);

        $this->assertSame('possibly_related', $result[0]['status']);
        $this->assertSame(.5, $result[0]['confidence']);
    }
}
