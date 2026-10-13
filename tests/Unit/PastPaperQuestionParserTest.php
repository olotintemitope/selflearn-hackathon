<?php

namespace Tests\Unit;

use App\Services\Learning\PastPaperQuestionParser;
use PHPUnit\Framework\TestCase;

class PastPaperQuestionParserTest extends TestCase
{
    public function test_waec_question_is_separated_from_observations_and_expected_responses(): void
    {
        $text = <<<'OCR'
        \section*{Question 2}
        1. Define strain energy. Write an expression for energy stored in a stretched wire.
        \section*{Observation}
        Some candidates confused strain energy with an elastic constant.
        EXPECTED RESPONSES:
        The energy stored in a material as a result of deformation.
        (b) E = Y A e^2 / (2l)
        Prev Next
        OCR;

        $candidate = (new PastPaperQuestionParser)->parse($text, .96)[0];

        $this->assertSame('2', $candidate['question_number']);
        $this->assertSame('structured_response', $candidate['question_type']);
        $this->assertStringContainsString('Define strain energy', $candidate['prompt']);
        $this->assertStringNotContainsString('Observation', $candidate['prompt']);
        $this->assertStringNotContainsString('Some candidates', $candidate['prompt']);
        $this->assertStringNotContainsString('EXPECTED RESPONSES', $candidate['prompt']);
        $this->assertSame('source_expected_response', $candidate['answer_source']);
        $this->assertStringContainsString('energy stored in a material', $candidate['expected_answer']);
        $this->assertSame('ready', $candidate['review_status']);
    }

    public function test_question_types_are_preserved_and_uncertain_ocr_requires_review(): void
    {
        $text = <<<'OCR'
        1. Which statement is correct?
        A. First choice
        B. Second choice
        C. Third choice
        D. Fourth choice
        2. Select all the correct answers.
        A. First choice
        B. Second choice
        C. Third choice
        3. The statement is true or false?
        A. True
        B. False
        4. Fill in the blank: water freezes at _____ degrees.
        OCR;

        $candidates = (new PastPaperQuestionParser)->parse($text, .55);

        $this->assertSame(['single_choice', 'multiple_select', 'true_false', 'fill_gap'], array_column($candidates, 'question_type'));
        $this->assertSame(['needs_review', 'needs_review', 'needs_review', 'needs_review'], array_column($candidates, 'review_status'));
        $this->assertSame('OCR confidence is below the review threshold.', $candidates[0]['review_reason']);
        $this->assertCount(4, $candidates[0]['options']);
        $this->assertStringNotContainsString('A. First choice', $candidates[0]['prompt']);
        $this->assertStringContainsString('A. First choice', $candidates[0]['source_text']);
    }

    public function test_mathpix_latex_itemized_exam_questions_are_split_and_partial_choices_are_held_for_review(): void
    {
        $text = <<<'OCR'
        \begin{itemize}
        \item[1.] Heat is defined as energy that flows from a \_\_ body to a \_\_ body.
        \begin{itemize}
        \item[A.] cold, hot
        \item[B.] hot, cold
        \item[C.] warm, cool
        \item[D.] cool, warm
        \end{itemize}
        \item[2.] The SI unit of heat is
        \begin{itemize}
        \item[A.] Kelvin
        \item[B.] Celsius
        \item[C.] Joule
        \item[D.] Newton
        \end{itemize}
        \item[3.] Temperature is the degree of hotness or coldness.
        \begin{itemize}
        \item[A.] weight, mass
        \item[(B)] hotness, coldness
        \item[C.] density, volume
        \item[D.] pressure, force
        \end{itemize}
        \item[4.] The SI unit of temperature is
        \begin{itemize}
        \item[] Kelvin
        \item[B.] Celsius
        \item[C.] Joule
        \item[D.] Fahrenheit
        \end{itemize}
        \end{itemize}
        OCR;

        $candidates = (new PastPaperQuestionParser)->parse($text, .99);

        $this->assertCount(4, $candidates);
        $this->assertSame(['1', '2', '3', '4'], array_column($candidates, 'question_number'));
        $this->assertSame('single_choice', $candidates[0]['question_type']);
        $this->assertCount(4, $candidates[0]['options']);
        $this->assertSame('cold, hot', $candidates[0]['options'][0]['text']);
        $this->assertStringContainsString('from a __ body to a __ body', $candidates[0]['prompt']);
        $this->assertStringNotContainsString('\\_', $candidates[0]['prompt']);
        $this->assertSame('ready', $candidates[0]['review_status']);
        $this->assertSame('hotness, coldness', $candidates[2]['options'][1]['text']);
        $this->assertSame('needs_review', $candidates[3]['review_status']);
        $this->assertSame('Question structure could not be identified confidently.', $candidates[3]['review_reason']);
        $this->assertStringNotContainsString('A. cold, hot', $candidates[0]['prompt']);
        $this->assertStringContainsString('cold, hot', $candidates[0]['source_text']);
    }

    public function test_copyright_and_page_footer_do_not_leak_into_last_question(): void
    {
        $text = <<<'OCR'
        \begin{itemize}
        \item[12.] When a solid is heated, it:
        \begin{itemize}
        \item[A.] contracts
        \item[B.] melts immediately
        \item[C.] expands
        \item[D.] becomes denser
        \end{itemize}
        Copyright (C) 2026
        Valencia College, Ibadan. (3rd Term Examination)
        Page 1
        OCR;

        $candidate = (new PastPaperQuestionParser)->parse($text, .99)[0];

        $this->assertSame('12', $candidate['question_number']);
        $this->assertSame('When a solid is heated, it:', $candidate['prompt']);
        $this->assertStringNotContainsString('Copyright', $candidate['prompt']);
        $this->assertStringNotContainsString('Valencia College', $candidate['prompt']);
        $this->assertStringNotContainsString('Page 1', $candidate['prompt']);
    }

    public function test_nested_numbered_latex_subparts_do_not_become_separate_questions(): void
    {
        $text = <<<'OCR'
        \begin{itemize}
        \item[1.] Answer both parts.
        \begin{itemize}
        \item[1.] Calculate the final temperature.
        \item[2.] Explain the change.
        \end{itemize}
        \item[2.] State the SI unit of heat.
        \end{itemize}
        OCR;

        $candidates = (new PastPaperQuestionParser)->parse($text, .99);

        $this->assertSame(['1', '2'], array_column($candidates, 'question_number'));
        $this->assertStringContainsString('Calculate the final temperature.', $candidates[0]['prompt']);
        $this->assertStringContainsString('Explain the change.', $candidates[0]['prompt']);
    }

    public function test_unlabeled_text_is_not_guessed_into_a_question(): void
    {
        $this->assertSame([], (new PastPaperQuestionParser)->parse('A paragraph about physics with no clear question boundary.'));
    }

    public function test_trailing_mark_allocation_is_captured_without_rewriting_question_source(): void
    {
        $text = "1. State the unit of force. (2 marks)\nA. newton\nB. joule";

        $candidate = (new PastPaperQuestionParser)->parse($text, .96)[0];

        $this->assertSame(2, $candidate['marks']);
        $this->assertSame($text, $candidate['source_text']);
        $this->assertStringContainsString('(2 marks)', $candidate['prompt']);
    }

    public function test_marks_are_not_guessed_when_the_question_has_multiple_allocations_or_non_trailing_marks(): void
    {
        $parser = new PastPaperQuestionParser;

        $multiple = $parser->parse('1. Explain two steps. (2 marks) Then compare them. (3 marks)', .96)[0];
        $nonTrailing = $parser->parse('1. Explain (2 marks) why the result changes.', .96)[0];

        $this->assertNull($multiple['marks']);
        $this->assertNull($nonTrailing['marks']);
    }

    public function test_explicit_exam_heading_can_bound_a_prose_question_but_visual_items_still_need_review(): void
    {
        $text = <<<'OCR'
        \section*{Question 1}
        The load-extension graph of an elastic material is illustrated below. Use the graph to determine the work done.
        \section*{Observation}
        Candidates often used the wrong formula.
        EXPECTED RESPONSE:
        Work done is the area under the graph.
        OCR;

        $candidate = (new PastPaperQuestionParser)->parse($text, .97)[0];

        $this->assertSame('1', $candidate['question_number']);
        $this->assertStringContainsString('Use the graph', $candidate['prompt']);
        $this->assertSame('needs_review', $candidate['review_status']);
        $this->assertSame('This question refers to visual evidence. Check the original page before using it.', $candidate['review_reason']);
        $this->assertStringNotContainsString('Candidates often', $candidate['prompt']);
        $this->assertStringContainsString('area under the graph', $candidate['expected_answer']);
    }

    public function test_examiner_performance_comments_do_not_become_part_of_question_wording(): void
    {
        $text = <<<'OCR'
        \section*{Question 1}
        The load-extension graph is illustrated below. Use the graph to determine the work done.
        The question was popular among candidates but majority resorted to the use of the wrong formula.
        Candidates performance was poor.
        \section*{Observation}
        The expected response is the area under the graph.
        OCR;

        $candidate = (new PastPaperQuestionParser)->parse($text, .95)[0];

        $this->assertStringContainsString('Use the graph to determine the work done.', $candidate['prompt']);
        $this->assertStringNotContainsString('popular among candidates', $candidate['prompt']);
        $this->assertStringNotContainsString('performance was poor', $candidate['prompt']);
        $this->assertSame('needs_review', $candidate['review_status']);
    }
}
