<?php

namespace App\Support;

final class LearnerText
{
    public static function withoutEmDash(string $text): string
    {
        return preg_replace('/\s*\x{2014}\s*/u', ': ', trim($text)) ?? trim($text);
    }
}
