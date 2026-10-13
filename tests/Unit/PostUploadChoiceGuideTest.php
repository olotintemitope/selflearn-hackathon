<?php

namespace Tests\Unit;

use App\Services\Learning\PostUploadChoiceGuide;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostUploadChoiceGuideTest extends TestCase
{
    #[DataProvider('recommendationCases')]
    public function test_suggests_only_routes_supported_by_the_available_material(string $intent, int $readyQuestions, int $heldQuestions, int $relatedConcepts, ?string $expectedRoute, string $expectedReason): void
    {
        $recommendation = (new PostUploadChoiceGuide)->recommend($intent, $readyQuestions, $heldQuestions, $relatedConcepts);

        $this->assertSame($expectedRoute, $recommendation['route']);
        $this->assertSame($expectedReason, $recommendation['reason']);
        $this->assertStringNotContainsString('—', $recommendation['reason']);
    }

    public static function recommendationCases(): array
    {
        return [
            'chosen practice route is available' => [
                'practise', 4, 0, 2, 'practise', 'You can practise now. There are 4 questions ready to try.',
            ],
            'chosen concept route is available' => [
                'learn_first', 4, 0, 1, 'learn_first', 'You can start by learning these ideas. There is 1 related idea ready to explore.',
            ],
            'chosen practice route is not available but concepts are' => [
                'practise', 0, 0, 2, null, 'There are no practice questions ready yet. You can learn 2 related ideas first.',
            ],
            'held questions do not count as practice ready' => [
                'practise', 0, 3, 0, null, 'Some practice questions need a quick review before you can try them.',
            ],
            'chosen concept route is not available but practice is' => [
                'learn_first', 5, 0, 0, null, 'There are no related ideas ready to learn yet. You can practise the available questions instead.',
            ],
            'unsure with practice only' => [
                'unsure', 2, 4, 0, 'practise', 'There are 2 practice questions ready, so you could try a few now.',
            ],
            'unsure with concepts only' => [
                'unsure', 0, 0, 3, 'learn_first', 'There are 3 related ideas ready, so you could start by learning them.',
            ],
            'unsure with both routes available gently suggests learning first' => [
                'unsure', 3, 0, 2, 'learn_first', 'There are 2 related ideas ready to learn first. You can also practise 3 questions now if you prefer.',
            ],
            'unsure with no available route' => [
                'unsure', 0, 2, 0, null, 'Some practice questions need a quick review. No related ideas are ready to learn yet.',
            ],
        ];
    }

    public function test_rejects_unknown_intent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PostUploadChoiceGuide)->recommend('ready', 1, 0, 1);
    }

    #[DataProvider('negativeCountCases')]
    public function test_rejects_negative_availability_counts(int $readyQuestions, int $heldQuestions, int $relatedConcepts): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PostUploadChoiceGuide)->recommend('unsure', $readyQuestions, $heldQuestions, $relatedConcepts);
    }

    public static function negativeCountCases(): array
    {
        return [
            'negative ready questions' => [-1, 0, 0],
            'negative held questions' => [0, -1, 0],
            'negative related concepts' => [0, 0, -1],
        ];
    }
}
