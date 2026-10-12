<?php

namespace App\Support;

final class ExtractedTextFormatter
{
    /**
     * Separate LaTeX-style section commands from OCR text for readable display.
     * The stored extraction remains untouched for evidence and reprocessing.
     *
     * @return array<int, array{type: 'text', text: string}|array{type: 'heading', level: int, text: string}>
     */
    public static function segments(string $text): array
    {
        preg_match_all('/\\\\(subsubsection|subsection|section)\*?\s*\{([^{}]*)\}/u', $text, $matches, PREG_OFFSET_CAPTURE);

        if ($matches[0] === []) {
            return [['type' => 'text', 'text' => $text]];
        }

        $segments = [];
        $cursor = 0;
        $headingLevels = ['section' => 4, 'subsection' => 5, 'subsubsection' => 6];

        foreach ($matches[0] as $index => [$command, $offset]) {
            if ($offset > $cursor) {
                $segments[] = ['type' => 'text', 'text' => substr($text, $cursor, $offset - $cursor)];
            }

            $kind = $matches[1][$index][0];
            $segments[] = [
                'type' => 'heading',
                'level' => $headingLevels[$kind],
                'text' => trim($matches[2][$index][0]),
            ];
            $cursor = $offset + strlen($command);
        }

        if ($cursor < strlen($text)) {
            $segments[] = ['type' => 'text', 'text' => substr($text, $cursor)];
        }

        return $segments;
    }
}
