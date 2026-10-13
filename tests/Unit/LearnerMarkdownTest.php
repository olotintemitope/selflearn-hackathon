<?php

namespace Tests\Unit;

use App\Support\LearnerMarkdown;
use PHPUnit\Framework\TestCase;

class LearnerMarkdownTest extends TestCase
{
    public function test_common_markdown_is_rendered_while_raw_html_and_unsafe_links_are_removed(): void
    {
        $html = (new LearnerMarkdown)->render("**Key idea**\n\n- First step\n- Second step\n\n<script>alert(1)</script>\n\n[unsafe](javascript:alert(1))");

        $this->assertStringContainsString('<strong>Key idea</strong>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>First step</li>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
