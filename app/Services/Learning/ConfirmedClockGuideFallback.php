<?php

namespace App\Services\Learning;

/** Creates a small, explicit guide when a visual clock source has been checked. */
final class ConfirmedClockGuideFallback
{
    /**
     * @param  list<array{id:int,locator?:array<string,mixed>}>  $chunks
     * @return array{concepts:list<array{id:string,name:string,description:string,source_chunk_ids:list<int>}>,dependencies:list<never>}|null
     */
    public function build(array $chunks): ?array
    {
        $examples = [];
        $sourceIds = [];

        foreach ($chunks as $chunk) {
            $analysis = $chunk['locator']['clock_diagrams'] ?? null;
            if (($analysis['status'] ?? null) !== 'learner_confirmed') {
                continue;
            }
            foreach ($analysis['faces'] ?? [] as $face) {
                $time = $face['confirmed_time'] ?? null;
                if (! is_int($face['index'] ?? null) || ! is_string($time)
                    || preg_match('/^(?:0[1-9]|1[0-2]):[0-5][0-9]$/', $time) !== 1) {
                    continue;
                }
                $examples[] = 'Clock '.$face['index'].': '.$time;
                $sourceIds[] = $chunk['id'];
            }
        }

        if ($examples === []) {
            return null;
        }

        return [
            'concepts' => [[
                'id' => 'confirmed_clock_reading',
                'name' => 'Read analogue clock faces',
                'description' => 'Supplementary clock-reading guidance, not transcribed from the source: identify the minute hand to find the minutes, then use the hour hand to identify the hour. At half past, the minute hand points to 6 and the hour hand is between hour numbers. Learner-confirmed examples from this page: '.implode('; ', $examples).'.',
                'source_chunk_ids' => array_values(array_unique($sourceIds)),
            ]],
            'dependencies' => [],
        ];
    }
}
