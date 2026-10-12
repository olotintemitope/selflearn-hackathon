# Slice 3: Quiz → evidence → recommended revision

**Backlog status:** Core features built; imported exam-bank follow-ups and real-PDF browser acceptance remain. See [the prioritized backlog](../BACKLOG.md).

## Acceptance criteria
- [x] Add a past-paper practice mode that starts from the learner's uploaded exam papers, not only AI-written questions from concept summaries. Reuse the app's local/Mathpix extraction route; do not use OpenAI to extract PDF text. It indexes extracted page text locally and supports both candidate-level practice and a set of clear questions from one paper.
- [x] Index conservative question candidates from related past-paper pages using local rules; preserve their source page, extraction version, wording, detected question type, options, hidden source response, and review status.
- [x] Separate examiner observations from question wording and keep detected expected responses out of the ordinary material preview.
- [x] Let the material owner build or refresh the candidate list from already-extracted pages without another OCR or AI call.
- [x] Let the material owner correct uncertain candidate wording, format, and choices against the original page. Keep the extracted transcription and source page unchanged; owner-reviewed candidates remain ungraded because review does not verify an answer key.
- [x] Allow the owner to submit ready candidates, keep needs-review candidates out of practice, hide detected responses until submission, and save an owner-scoped attempt with source page and answer basis.
- [x] Deterministically score only objective answers whose extracted source response is an exact, unambiguous option label; leave other responses ungraded rather than inventing a key.
- [x] Preserve question boundaries, original wording, numbering/subparts, paper/year, marks, page/source locator, and detected response format. Keep the OCR question transcription beside the normalized question so the learner can review transcription fidelity. Paper year is recorded only when one year is clear in the material title or original filename; marks are recorded only for one unambiguous allocation. Unknown or ambiguous metadata remains blank. Full extracted page text remains hidden until after submission because it may contain expected responses.
- [x] Detect and render single-answer MCQ, multi-select MCQ, true/false, fill-in-the-gap, and written/structured-response questions without silently changing their format. Diagram-dependent prompts are marked for review, not guessed.
- [x] Let the learner practise all clear detected questions from one selected uploaded paper in page/question order, with source provenance visible before and after submission. Questions needing review remain excluded.
- [x] Give one-paper practice an exam-style interface with one question at a time, question navigation, answer progress, review flags, learner-selected practice timing, and automatic submission at expiry. Clearly distinguish the chosen practice timer from official paper timing.
- [x] Enforce practice deadlines on the server for concept exams and one-paper sessions. Ignore late answer payloads, finalize expired sessions, and show unanswered questions clearly.
- [x] Let the learner select at least two ready papers from one owned study space and practise their current clear questions in selected-paper order. Snapshot the selected papers and questions; show each question's own paper, year hint, and page through results.
- [ ] Use official marks or timing only after verifying their association with the selected papers and questions. Until then, do not total detected marks and keep timing learner-selected.
- [ ] Match a supplied marking scheme/answer key to its specific paper and question. Detected source responses are currently shown only after submission and explicitly labeled unverified; uploading/matching a marking scheme is not implemented.
- [ ] After submission, give question-format-aware marking and step-by-step solutions. Current practice saves unsupported/ambiguous responses without scoring and only scores exact unambiguous objective response labels; imported-paper written answers do not yet receive rubric-based feedback or worked solutions.
- [x] Concept-tagged, source-chunk-linked exam-style MCQs.
- [x] Server-side answer keys and grading.
- [x] Unknown state before practice; report the latest practice signal, combining MCQ and marked written points without treating unscored writing as zero.
- [x] Idempotent submissions using client UUIDs.
- [x] Recommend a weak prerequisite or below-threshold concept after feedback; link directly to its source lesson, tutor, and practice actions.
- [x] Follow-up practice can be generated again from the study path.
- [x] Offer separate Guided practice and timed Exam mode; exam questions must be source-supported, with no source cues or feedback until submission.
- [x] Release exam score and worked feedback after submission, without mixing it into guided next-lesson advice.
- [x] Show the learner a source-space goal and the number of path concepts practised, with a completion checkpoint that does not claim mastery.
- [x] Keep tutor retrieval and citation-failure fallback scoped to the learner's selected concept; do not guess a fallback topic for ambiguous questions.

## Out of scope
Scientifically validated mastery.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence. Feature tests travel beyond each server deadline, submit otherwise-correct answers, and verify that no late answer is scored and the result is finalized as unanswered. Expired past-paper sessions also finalize when reopened.

## Current limitation
The imported bank supports single-paper and mixed-paper practice from current clear questions, but it is not an official, proctored, or fully marked WAEC simulation. The learner selects a 30, 60, or 90 minute practice timer; it does not claim to reflect official paper timing. Mixed sets accept papers from one owned study space and preserve each question's source paper, year hint, and page. Paper years are upload-label hints, not official verification. Detected marks are not summed or used for scoring. Owner-checked candidates can be corrected and practised, but are always ungraded until answer-key verification is implemented. Only untouched clear objective answer labels are scored, as an unverified comparison. Written and fill-gap answers remain ungraded. Marking-scheme matching, official timing association, and imported-question worked solutions remain open. Marks are kept unknown when extraction is ambiguous. The live browser flow has been verified with synthetic question rows; a fresh real-PDF exam-mode run is still needed. The concept-level exam generates original single-answer, four-option MCQs and one AI-marked written response from concept-linked study chunks; its 20-minute timer is not a full-paper simulation or proctored exam. Latest-attempt points and the 70% review prompt are planning heuristics, not validated mastery.
