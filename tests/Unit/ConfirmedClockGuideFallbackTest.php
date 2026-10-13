<?php

namespace Tests\Unit;

use App\Services\Learning\ConfirmedClockGuideFallback;
use PHPUnit\Framework\TestCase;

class ConfirmedClockGuideFallbackTest extends TestCase
{
    public function test_it_builds_a_source_linked_fallback_from_only_learner_confirmed_times(): void
    {
        $result = (new ConfirmedClockGuideFallback)->build([
            ['id' => 51, 'locator' => ['clock_diagrams' => [
                'status' => 'learner_confirmed',
                'faces' => [
                    ['index' => 1, 'candidate_time' => '03:00', 'confirmed_time' => '03:00'],
                    ['index' => 2, 'candidate_time' => null, 'confirmed_time' => '05:30'],
                ],
            ]]],
            ['id' => 52, 'locator' => ['clock_diagrams' => [
                'status' => 'needs_learner_confirmation',
                'faces' => [['index' => 1, 'confirmed_time' => '09:15']],
            ]]],
        ]);

        $this->assertSame([51], $result['concepts'][0]['source_chunk_ids']);
        $this->assertStringContainsString('Supplementary clock-reading guidance, not transcribed from the source', $result['concepts'][0]['description']);
        $this->assertStringContainsString('Clock 1: 03:00; Clock 2: 05:30', $result['concepts'][0]['description']);
        $this->assertStringNotContainsString('09:15', $result['concepts'][0]['description']);
    }

    public function test_it_creates_nothing_when_times_are_not_confirmed_or_invalid(): void
    {
        $this->assertNull((new ConfirmedClockGuideFallback)->build([
            ['id' => 51, 'locator' => ['clock_diagrams' => [
                'status' => 'learner_confirmed',
                'faces' => [['index' => 1, 'confirmed_time' => '99:99']],
            ]]],
        ]));
    }
}
