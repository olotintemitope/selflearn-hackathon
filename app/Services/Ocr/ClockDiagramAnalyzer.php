<?php

namespace App\Services\Ocr;

/**
 * Local, clock-specific visual analysis. This is deliberately not a general
 * diagram reader. Every result is a candidate that must be checked by the
 * learner before it is added to source evidence.
 */
final class ClockDiagramAnalyzer
{
    public const VERSION = 'local-clock-reader-v1';

    private const MAX_SIDE = 1000;

    private const DARK_THRESHOLD = 145;

    /**
     * @return array{version:string,status:string,faces:list<array{index:int,candidate_time:?string,match_quality:string,bounds:array{left:float,top:float,width:float,height:float}}>}|null
     */
    public function analyze(string $bytes): ?array
    {
        if (! function_exists('imagecreatefromstring') || $bytes === '') {
            return null;
        }

        try {
            $image = @imagecreatefromstring($bytes);
        } catch (\Throwable) {
            return null;
        }
        if (! $image instanceof \GdImage) {
            return null;
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        if ($originalWidth < 100 || $originalHeight < 100 || $originalWidth * $originalHeight > 40_000_000) {
            imagedestroy($image);

            return null;
        }

        $scale = min(1, self::MAX_SIDE / max($originalWidth, $originalHeight));
        if ($scale < 1) {
            $width = max(1, (int) round($originalWidth * $scale));
            $height = max(1, (int) round($originalHeight * $scale));
            $resized = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);
            imagedestroy($image);
            $image = $resized;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $mask = $this->darkPixelMask($image, $width, $height);
        imagedestroy($image);

        $faces = [];
        foreach ($this->components($mask, $width, $height) as $component) {
            $boxWidth = $component['right'] - $component['left'] + 1;
            $boxHeight = $component['bottom'] - $component['top'] + 1;
            $minimum = min($boxWidth, $boxHeight);
            $maximum = max($boxWidth, $boxHeight);
            $aspect = $minimum / max(1, $maximum);
            $area = $boxWidth * $boxHeight;

            if ($minimum < 28 || $maximum > min($width, $height) * 0.75
                || $aspect < 0.76 || $component['pixels'] < 70
                || $component['pixels'] > $area * 0.45) {
                continue;
            }

            $coverage = $this->circleCoverage($mask, $width, $height, $component);
            if ($coverage < 0.42) {
                continue;
            }

            $faces[] = [
                'left' => $component['left'], 'top' => $component['top'],
                'right' => $component['right'], 'bottom' => $component['bottom'],
                'coverage' => $coverage,
            ];
        }

        usort($faces, fn (array $a, array $b): int => [$a['top'], $a['left']] <=> [$b['top'], $b['left']]);
        $faces = $this->removeOverlappingFaces($faces);

        if ($faces === []) {
            return null;
        }

        $normalizedFaces = [];
        foreach ($faces as $index => $face) {
            $read = $this->readHands($mask, $width, $height, $face);
            $normalizedFaces[] = [
                'index' => $index + 1,
                'candidate_time' => $read['time'],
                'match_quality' => $read['quality'],
                'bounds' => [
                    'left' => round($face['left'] / $width, 4),
                    'top' => round($face['top'] / $height, 4),
                    'width' => round(($face['right'] - $face['left'] + 1) / $width, 4),
                    'height' => round(($face['bottom'] - $face['top'] + 1) / $height, 4),
                ],
            ];
        }

        return [
            'version' => self::VERSION,
            'status' => 'needs_learner_confirmation',
            'faces' => $normalizedFaces,
        ];
    }

    /** @return string One byte per pixel, with 1 marking dark ink. */
    private function darkPixelMask(\GdImage $image, int $width, int $height): string
    {
        $mask = str_repeat("\0", $width * $height);
        $trueColor = imageistruecolor($image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                if ($trueColor) {
                    $red = ($color >> 16) & 0xFF;
                    $green = ($color >> 8) & 0xFF;
                    $blue = $color & 0xFF;
                } else {
                    $rgb = imagecolorsforindex($image, $color);
                    $red = $rgb['red'];
                    $green = $rgb['green'];
                    $blue = $rgb['blue'];
                }

                $luminance = (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue);
                if ($luminance < self::DARK_THRESHOLD) {
                    $mask[$y * $width + $x] = "\1";
                }
            }
        }

        return $mask;
    }

    /**
     * @return list<array{left:int,top:int,right:int,bottom:int,pixels:int}>
     */
    private function components(string $mask, int $width, int $height): array
    {
        $length = $width * $height;
        $visited = str_repeat("\0", $length);
        $components = [];

        for ($start = 0; $start < $length; $start++) {
            if ($mask[$start] !== "\1" || $visited[$start] === "\1") {
                continue;
            }

            $queue = [$start];
            $visited[$start] = "\1";
            $left = $width;
            $top = $height;
            $right = 0;
            $bottom = 0;
            $pixels = 0;

            for ($head = 0; $head < count($queue); $head++) {
                $position = $queue[$head];
                $x = $position % $width;
                $y = intdiv($position, $width);
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
                $pixels++;

                for ($dy = -1; $dy <= 1; $dy++) {
                    $nextY = $y + $dy;
                    if ($nextY < 0 || $nextY >= $height) {
                        continue;
                    }
                    for ($dx = -1; $dx <= 1; $dx++) {
                        if ($dx === 0 && $dy === 0) {
                            continue;
                        }
                        $nextX = $x + $dx;
                        if ($nextX < 0 || $nextX >= $width) {
                            continue;
                        }
                        $next = $nextY * $width + $nextX;
                        if ($mask[$next] === "\1" && $visited[$next] !== "\1") {
                            $visited[$next] = "\1";
                            $queue[] = $next;
                        }
                    }
                }
            }

            if ($pixels >= 70) {
                $components[] = compact('left', 'top', 'right', 'bottom', 'pixels');
            }
        }

        return $components;
    }

    /** @param array{left:int,top:int,right:int,bottom:int,pixels:int} $component */
    private function circleCoverage(string $mask, int $width, int $height, array $component): float
    {
        $centerX = ($component['left'] + $component['right']) / 2;
        $centerY = ($component['top'] + $component['bottom']) / 2;
        $radius = min($component['right'] - $component['left'], $component['bottom'] - $component['top']) * 0.49;
        $hits = 0;
        $samples = 72;

        for ($sample = 0; $sample < $samples; $sample++) {
            $angle = 2 * M_PI * $sample / $samples;
            $x = (int) round($centerX + cos($angle) * $radius);
            $y = (int) round($centerY + sin($angle) * $radius);
            if ($this->hasDarkPixelNear($mask, $width, $height, $x, $y, 2)) {
                $hits++;
            }
        }

        return $hits / $samples;
    }

    /** @param array{left:int,top:int,right:int,bottom:int,pixels:int,coverage:float} $face */
    private function readHands(string $mask, int $width, int $height, array $face): array
    {
        $centerX = ($face['left'] + $face['right']) / 2;
        $centerY = ($face['top'] + $face['bottom']) / 2;
        $radius = min($face['right'] - $face['left'], $face['bottom'] - $face['top']) / 2;
        $step = max(0.75, $radius * 0.024);
        $minimumRadius = max(3, $radius * 0.12);
        $maximumRadius = $radius * 0.70;
        $scores = [];

        for ($degrees = 0; $degrees < 360; $degrees++) {
            $angle = deg2rad($degrees);
            $run = 0;
            $longestRun = 0;
            for ($distance = $minimumRadius; $distance <= $maximumRadius; $distance += $step) {
                $x = (int) round($centerX + cos($angle) * $distance);
                $y = (int) round($centerY - sin($angle) * $distance);
                if ($this->hasDarkPixelNear($mask, $width, $height, $x, $y, 1)) {
                    $run++;
                    $longestRun = max($longestRun, $run);
                } else {
                    $run = 0;
                }
            }
            $scores[$degrees] = $longestRun;
        }

        arsort($scores);
        $rays = [];
        foreach ($scores as $degrees => $length) {
            if ($length < 5) {
                break;
            }
            $separated = true;
            foreach ($rays as $ray) {
                $difference = abs($degrees - $ray['degrees']);
                if (min($difference, 360 - $difference) < 15) {
                    $separated = false;
                    break;
                }
            }
            if ($separated) {
                $rays[] = ['degrees' => (int) $degrees, 'length' => $length];
                if (count($rays) === 3) {
                    break;
                }
            }
        }

        if ($rays === []) {
            return ['time' => null, 'quality' => 'ambiguous'];
        }

        if (count($rays) === 1) {
            $minuteDegrees = $this->clockDegrees($rays[0]['degrees']);
            $minute = $this->nearestFiveMinutes($minuteDegrees);
            if ($minute === 0 && min($minuteDegrees, 360 - $minuteDegrees) <= 7) {
                return ['time' => '12:00', 'quality' => 'ambiguous'];
            }
            if ($minute === 30 && abs($minuteDegrees - 180) <= 7) {
                return ['time' => '06:00', 'quality' => 'ambiguous'];
            }

            return ['time' => null, 'quality' => 'ambiguous'];
        }

        $minuteRay = $rays[0];
        $hourRay = $rays[1];
        if ($hourRay['length'] > $minuteRay['length']) {
            [$minuteRay, $hourRay] = [$hourRay, $minuteRay];
        }

        $minutePosition = $this->clockDegrees($minuteRay['degrees']);
        $minute = $this->nearestFiveMinutes($minutePosition);
        $hourPosition = $this->clockDegrees($hourRay['degrees']) / 30;
        $hour = ((int) floor($hourPosition + 0.05)) % 12;
        $expectedHourPosition = fmod($hour + $minute / 60, 12);
        $hourError = abs($hourPosition - $expectedHourPosition);
        $hourError = min($hourError, 12 - $hourError);
        $minuteError = $this->circularDifference($minutePosition, $minute * 6);
        $angleSeparation = abs($minuteRay['degrees'] - $hourRay['degrees']);
        $angleSeparation = min($angleSeparation, 360 - $angleSeparation);
        $quality = $minuteRay['length'] >= $hourRay['length'] + 2
            && $hourError <= 0.35 && $minuteError <= 8 && $angleSeparation >= 9
            ? 'candidate'
            : 'ambiguous';

        return [
            'time' => sprintf('%02d:%02d', $hour === 0 ? 12 : $hour, $minute),
            'quality' => $quality,
        ];
    }

    private function clockDegrees(int $rayDegrees): float
    {
        return fmod(90 - $rayDegrees + 360, 360);
    }

    private function nearestFiveMinutes(float $degrees): int
    {
        return ((int) round($degrees / 30) % 12) * 5;
    }

    private function circularDifference(float $left, float $right): float
    {
        $difference = abs($left - $right);

        return min($difference, 360 - $difference);
    }

    private function hasDarkPixelNear(string $mask, int $width, int $height, int $x, int $y, int $radius): bool
    {
        for ($offsetY = -$radius; $offsetY <= $radius; $offsetY++) {
            $sampleY = $y + $offsetY;
            if ($sampleY < 0 || $sampleY >= $height) {
                continue;
            }
            for ($offsetX = -$radius; $offsetX <= $radius; $offsetX++) {
                $sampleX = $x + $offsetX;
                if ($sampleX < 0 || $sampleX >= $width) {
                    continue;
                }
                if ($mask[$sampleY * $width + $sampleX] === "\1") {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array{left:int,top:int,right:int,bottom:int,coverage:float}> $faces */
    private function removeOverlappingFaces(array $faces): array
    {
        $kept = [];
        foreach ($faces as $candidate) {
            $overlaps = false;
            foreach ($kept as $existing) {
                $centerDistance = hypot(
                    (($candidate['left'] + $candidate['right']) - ($existing['left'] + $existing['right'])) / 2,
                    (($candidate['top'] + $candidate['bottom']) - ($existing['top'] + $existing['bottom'])) / 2,
                );
                $existingRadius = min($existing['right'] - $existing['left'], $existing['bottom'] - $existing['top']) / 2;
                $candidateRadius = min($candidate['right'] - $candidate['left'], $candidate['bottom'] - $candidate['top']) / 2;
                if ($centerDistance < min($existingRadius, $candidateRadius) * 0.7) {
                    $overlaps = true;
                    break;
                }
            }
            if (! $overlaps) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }
}
