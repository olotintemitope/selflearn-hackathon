<?php

namespace Tests\Unit;

use App\Services\Learning\ClockPracticeQuestion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClockPracticeQuestionTest extends TestCase
{
    #[DataProvider('clockBoundaryCases')]
    public function test_hands_use_minute_and_fractional_hour_geometry(int $hour, int $minute, array $expectedMinuteHand, array $expectedHourHand): void
    {
        $clock = (new ClockPracticeQuestion)->make($hour, $minute);

        $this->assertSame($expectedMinuteHand, $clock['minute_hand']);
        $this->assertSame($expectedHourHand, $clock['hour_hand']);
        $this->assertSame(sprintf('%d:%02d', $hour, $minute), $clock['answer']);
        $this->assertCount(4, $clock['options']);
        $this->assertContains($clock['answer'], $clock['options']);
        $this->assertCount(4, array_unique($clock['options']));
    }

    public static function clockBoundaryCases(): array
    {
        return [
            '12 o’clock' => [12, 0, ['x' => 100.0, 'y' => 24.0], ['x' => 100.0, 'y' => 46.0]],
            'quarter past three' => [3, 15, ['x' => 176.0, 'y' => 100.0], ['x' => 153.54, 'y' => 107.05]],
            'five fifty nine before six' => [5, 59, ['x' => 92.06, 'y' => 24.42], ['x' => 100.47, 'y' => 154.0]],
            'six o’clock transition' => [6, 0, ['x' => 100.0, 'y' => 24.0], ['x' => 100.0, 'y' => 154.0]],
        ];
    }

    public function test_rejects_malformed_clock_times(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ClockPracticeQuestion)->make(13, 60);
    }
}
