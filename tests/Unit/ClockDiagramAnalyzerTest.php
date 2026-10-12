<?php

namespace Tests\Unit;

use App\Services\Ocr\ClockDiagramAnalyzer;
use PHPUnit\Framework\TestCase;

class ClockDiagramAnalyzerTest extends TestCase
{
    public function test_it_reads_synthetic_clock_faces_as_candidates_that_need_confirmation(): void
    {
        $image = imagecreatetruecolor(420, 220);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $this->drawClock($image, $black, 210, 110, 5, 30);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $result = (new ClockDiagramAnalyzer)->analyze($bytes);

        $this->assertSame(ClockDiagramAnalyzer::VERSION, $result['version']);
        $this->assertSame('needs_learner_confirmation', $result['status']);
        $this->assertSame(['05:30'], array_column($result['faces'], 'candidate_time'));
    }

    public function test_it_reads_a_simple_three_oclock_clock_face(): void
    {
        $image = imagecreatetruecolor(220, 220);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $this->drawClock($image, $black, 110, 110, 3, 0);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $result = (new ClockDiagramAnalyzer)->analyze($bytes);

        $this->assertSame(['03:00'], array_column($result['faces'], 'candidate_time'), json_encode($result['faces'], JSON_THROW_ON_ERROR));
    }

    public function test_it_returns_no_analysis_for_non_image_bytes(): void
    {
        $this->assertNull((new ClockDiagramAnalyzer)->analyze('not an image'));
    }

    private function drawClock(\GdImage $image, int $black, int $centerX, int $centerY, int $hour, int $minute): void
    {
        $radius = 52;
        imagesetthickness($image, 5);
        imageellipse($image, $centerX, $centerY, $radius * 2, $radius * 2, $black);
        $minuteDegrees = $minute * 6;
        $hourDegrees = (($hour % 12) * 30) + ($minute * 0.5);
        foreach ([[$minuteDegrees, 0.76], [$hourDegrees, 0.5]] as [$degrees, $length]) {
            $radians = deg2rad(90 - $degrees);
            $x = (int) round($centerX + cos($radians) * $radius * $length);
            $y = (int) round($centerY - sin($radians) * $radius * $length);
            imageline($image, $centerX, $centerY, $x, $y, $black);
        }
        imagesetthickness($image, 1);
    }
}
