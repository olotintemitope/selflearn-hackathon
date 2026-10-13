<?php

namespace Tests\Unit;

use App\Support\LearnerText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LearnerTextTest extends TestCase
{
    #[Test]
    public function it_replaces_em_dash_punctuation_in_generated_learner_text(): void
    {
        $emDash = "\u{2014}";
        $this->assertSame('Energy changes: not disappears.', LearnerText::withoutEmDash('Energy changes '.$emDash.' not disappears.'));
        $this->assertSame('A: B', LearnerText::withoutEmDash('A'.$emDash.'B'));
        $this->assertSame('Use an en dash – only if needed.', LearnerText::withoutEmDash('Use an en dash – only if needed.'));
    }
}
