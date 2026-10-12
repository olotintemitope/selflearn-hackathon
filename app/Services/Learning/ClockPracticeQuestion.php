<?php

namespace App\Services\Learning;

use InvalidArgumentException;

/** Deterministic analogue clock practice geometry; contains no provider calls. */
class ClockPracticeQuestion
{
    public function make(int $hour, int $minute): array
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('Clock time is outside the supported range.');
        }

        $minuteAngle = $minute * 6;
        $hourAngle = (($hour % 12) + ($minute / 60)) * 30;

        $answer = sprintf('%d:%02d', $hour, $minute);
        $nextHour = $hour === 12 ? 1 : $hour + 1;
        $previousHour = $hour === 1 ? 12 : $hour - 1;
        $options = array_values(array_unique([
            $answer,
            sprintf('%d:%02d', $hour, ($minute + 15) % 60),
            sprintf('%d:%02d', $nextHour, $minute),
            sprintf('%d:%02d', $previousHour, $minute),
        ]));
        shuffle($options);

        return [
            'hour' => $hour,
            'minute' => $minute,
            'hour_hand' => $this->point($hourAngle, 54),
            'minute_hand' => $this->point($minuteAngle, 76),
            'answer' => $answer,
            'options' => $options,
        ];
    }

    public function random(): array
    {
        $hour = random_int(1, 12);
        $minute = [0, 15, 30, 45][random_int(0, 3)];

        return $this->make($hour, $minute);
    }

    /** @return array{x: float, y: float} */
    private function point(float $degreesFromTwelve, float $length): array
    {
        $radians = deg2rad($degreesFromTwelve);

        return [
            'x' => round(100 + sin($radians) * $length, 2),
            'y' => round(100 - cos($radians) * $length, 2),
        ];
    }
}
