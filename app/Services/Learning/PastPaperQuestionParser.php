<?php

namespace App\Services\Learning;

/**
 * Extracts conservative, page-linked question candidates from OCR text.
 *
 * This parser does not use a model. If it cannot identify a question
 * boundary, it returns no candidate rather than inventing one.
 */
final class PastPaperQuestionParser
{
    public const VERSION = 'page-question-parser-v3';

    /**
     * @return array<int, array{question_number:string, prompt:string, source_text:string, question_type:string, options:array<int, array{id:string,text:string}>, marks:?int, expected_answer:?string, answer_source:string, review_status:string, review_reason:?string, confidence:float}>
     */
    public function parse(string $content, ?float $ocrConfidence = null): array
    {
        $content = $this->normalizeExtractedBlanks($this->normalizeLatexLists(str_replace(["\r\n", "\r"], "\n", trim($content))));
        if ($content === '') {
            return [];
        }

        $paperQuestionNumber = $this->headingQuestionNumber($content);
        $expectedAnswer = $this->expectedAnswer($content);
        $questionText = $this->questionSection($content);
        $sections = $this->numberedSections($questionText);
        if ($paperQuestionNumber !== null && count($sections) === 1) {
            $sections[0][0] = $paperQuestionNumber;
        }
        $results = [];

        foreach ($sections as [$number, $text, $sourceText]) {
            $prompt = trim($text);
            if ($prompt === '' || mb_strlen($prompt) < 8) {
                continue;
            }

            [$type, $options, $structureIsClear] = $this->classify($prompt);
            if ($options !== []) {
                $prompt = trim(preg_replace('/^\s*[A-H][.)]\s+.+$/imu', '', $prompt) ?? $prompt);
            }
            $visualEvidenceRequired = preg_match('/\b(graph|figure|diagram|illustration|table|shown\s+below|pictured)\b/i', $prompt) === 1;
            $structureIsClear = $structureIsClear && ! $visualEvidenceRequired;
            $confidence = $ocrConfidence === null ? 0.5 : max(0.0, min(1.0, $ocrConfidence));
            $results[] = [
                'question_number' => (string) $number,
                'prompt' => $prompt,
                'source_text' => trim($sourceText),
                'question_type' => $type,
                'options' => $options,
                'marks' => $this->unambiguousMarks($sourceText),
                'expected_answer' => $expectedAnswer,
                'answer_source' => $expectedAnswer === null ? 'not_detected' : 'source_expected_response',
                'review_status' => ($confidence < 0.8 || ! $structureIsClear) ? 'needs_review' : 'ready',
                'review_reason' => $confidence < 0.8
                    ? ($ocrConfidence === null ? 'OCR confidence is unavailable.' : 'OCR confidence is below the review threshold.')
                    : ($visualEvidenceRequired
                        ? 'This question refers to visual evidence. Check the original page before using it.'
                        : (! $structureIsClear ? 'Question structure could not be identified confidently.' : null)),
                'confidence' => $confidence,
            ];
        }

        return $results;
    }

    private function unambiguousMarks(string $sourceText): ?int
    {
        $sourceText = trim($sourceText);
        preg_match_all('/\(\s*(\d{1,3})\s*(?:marks?|mks?)\s*\)|\[\s*(\d{1,3})\s*(?:marks?|mks?)\s*\]|(?<![\pL\d])(\d{1,3})\s*(?:marks?|mks?)\b/iu', $sourceText, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if (count($matches) !== 1) {
            return null;
        }

        $match = $matches[0];
        $trailingText = substr($sourceText, $match[0][1] + strlen($match[0][0]));
        $trailingText = preg_replace('/^\s*[A-H][.)]\s+.*(?:\R|$)/mu', '', $trailingText) ?? $trailingText;
        if (trim($trailingText) !== '') {
            return null;
        }

        foreach ([1, 2, 3] as $group) {
            if (($matches[0][$group][1] ?? -1) >= 0) {
                return (int) $matches[0][$group][0];
            }
        }

        return null;
    }

    private function questionSection(string $content): string
    {
        // WAEC e-learning pages and some OCR output explicitly label the exam
        // question followed by examiner observations. Keep those observations
        // and expected responses out of the question prompt.
        $content = preg_replace_callback('/\\\\section\*\s*\{\s*Question\s+(\d+)\s*\}/iu', fn (array $match): string => "\n@@QUESTION:".$match[1]."@@\n", $content) ?? $content;
        $content = preg_replace_callback('/^\s*Question\s+(\d+)\s*:?\s*$/imu', fn (array $match): string => "\n@@QUESTION:".$match[1]."@@\n", $content) ?? $content;
        $content = preg_split('/(?:\\\\section\*\s*\{\s*(?:Observation|Comments?|Expected\s+Responses?|Answers?)\s*\}|^\s*(?:Observation|Examiner[’\']?s?\s+comments?|Expected\s+Responses?|Answers?|Marking\s+Scheme)\s*:)/imu', $content)[0] ?? $content;
        $content = preg_split('/\b(?:The question was popular among candidates|Candidates[’\']?\s+performance was poor|Most candidates (?:did not|failed to|were unable to)|Many candidates (?:did not|failed to|were unable to))\b/iu', $content)[0] ?? $content;
        $content = preg_replace('/\s*Copyright\s*(?:\(C\)|©)\s*\d{4}[^\n]*(?:\n|$)/iu', "\n", $content) ?? $content;
        $content = preg_replace('/\s*Page\s+\d+\s*$/iu', '', $content) ?? $content;
        $content = preg_replace('/(?:\R\s*)+[^\n]*(?:College|School|University)[^\n]*(?:Term|Examination|Exam)[^\n]*\s*$/iu', '', $content) ?? $content;

        return trim($content);
    }

    private function normalizeLatexLists(string $content): string
    {
        $listDepth = 0;

        return preg_replace_callback(
            '/\\\\(?:begin\s*\{itemize\}|end\s*\{itemize\}|item\s*(?:\[\s*([^\]]*?)\s*\])?)/iu',
            function (array $match) use (&$listDepth): string {
                $token = strtolower($match[0]);
                if (str_starts_with($token, '\\begin')) {
                    $listDepth++;

                    return "\n";
                }
                if (str_starts_with($token, '\\end')) {
                    $listDepth = max(0, $listDepth - 1);

                    return "\n";
                }

                $label = trim($match[1] ?? '');
                if ($label === '') {
                    return "\n";
                }
                if (preg_match('/^\(?([A-H])[.)]?\)?$/iu', $label, $option)) {
                    return "\n".strtoupper($option[1]).'. ';
                }
                if (preg_match('/^(\d{1,2})[.)]?$/u', $label, $number)) {
                    return $listDepth <= 1
                        ? "\n".$number[1].'. '
                        : "\n(".$number[1].') ';
                }

                return "\n".$label.' ';
            },
            $content,
        ) ?? $content;
    }

    private function normalizeExtractedBlanks(string $content): string
    {
        return preg_replace_callback('/(?:\\\\_){2,}/u', fn (array $match): string => str_repeat('_', intdiv(strlen($match[0]), 2)), $content) ?? $content;
    }

    private function headingQuestionNumber(string $content): ?string
    {
        if (preg_match('/(?:\\\\section\*\s*\{\s*Question\s+(\d+)\s*\}|^\s*Question\s+(\d+)\b)/imu', $content, $match)) {
            return $match[1] ?: $match[2];
        }

        return null;
    }

    private function expectedAnswer(string $content): ?string
    {
        if (! preg_match('/(?:EXPECTED\s+RESPONSES?|SUGGESTED\s+ANSWERS?|MARKING\s+SCHEME)\s*:?\s*([\s\S]+)$/iu', $content, $matches)) {
            return null;
        }

        $answer = trim($matches[1]);
        $answer = preg_replace('/(?:^|\n)\s*(?:Prev|Previous|Next)\s*$/iu', '', $answer) ?? $answer;
        $answer = trim($answer);

        return $answer === '' ? null : $answer;
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function numberedSections(string $content): array
    {
        if (preg_match('/^\s*@@QUESTION:\d+@@\s*$/mu', $content)) {
            preg_match_all('/^\s*@@QUESTION:(\d+)@@\s*$/mu', $content, $headers, PREG_OFFSET_CAPTURE);
            $sections = [];
            foreach ($headers[0] as $index => [$marker, $offset]) {
                $nextOffset = $headers[0][$index + 1][1] ?? strlen($content);
                $body = trim(substr($content, $offset + strlen($marker), $nextOffset - ($offset + strlen($marker))));
                if ($body !== '') {
                    $sections[] = [$headers[1][$index][0], $body, $body];
                }
            }

            return $sections;
        }

        preg_match_all('/^\s*@@QUESTION:(\d+)@@\s*$|^\s*(\d{1,2})[.)]\s+(\S.*)$/mu', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            return [];
        }

        $sections = [];
        foreach ($matches[0] as $index => [$marker, $offset]) {
            $nextOffset = $matches[0][$index + 1][1] ?? strlen($content);
            $number = $matches[1][$index][0] !== '' ? $matches[1][$index][0] : ($matches[2][$index][0] ?: '');
            $start = $offset + strlen($marker);
            $body = trim(substr($content, $start, $nextOffset - $start));
            $sourceText = $body;

            if ($matches[3][$index][0] !== '') {
                $questionLine = trim($matches[3][$index][0]);
                $body = trim($questionLine."\n".$body);
                $sourceText = trim($marker.($sourceText === '' ? '' : "\n".$sourceText));
            }
            if ($number !== '') {
                $sections[] = [$number, $body, $sourceText];
            }
        }

        return $sections;
    }

    /**
     * @return array{0:string,1:array<int, array{id:string,text:string}>,2:bool}
     */
    private function classify(string $prompt): array
    {
        preg_match_all('/^\s*([A-H])[.)]\s+(.+)$/imu', $prompt, $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $match) {
            $options[] = ['id' => strtoupper($match[1]), 'text' => trim($match[2])];
        }
        $optionIds = array_column($options, 'id');
        $hasMultipleOptions = count($optionIds) >= 2;
        $consecutiveOptions = $hasMultipleOptions
            && $optionIds === range('A', chr(ord('A') + count($optionIds) - 1));

        if ($consecutiveOptions && count($options) === 2 && collect($options)->every(fn (array $option): bool => preg_match('/^(true|false)$/i', $option['text']) === 1)) {
            return ['true_false', $options, true];
        }
        if ($hasMultipleOptions && preg_match('/\b(select|choose|tick|identify)\s+(all|every|two|three|more than one)\b/i', $prompt)) {
            return ['multiple_select', $options, $consecutiveOptions];
        }
        if ($hasMultipleOptions) {
            return ['single_choice', $options, $consecutiveOptions];
        }
        if (preg_match('/_{2,}|\.{3,}|\b(fill\s+in\s+the\s+(?:blank|gap)|complete\s+the\s+sentence)\b/i', $prompt)) {
            return ['fill_gap', [], true];
        }

        return ['structured_response', [], true];
    }
}
