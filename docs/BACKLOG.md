# Product backlog

Last reconciled: 2026-10-13. This is the canonical priority and status index. The linked slice documents retain the acceptance criteria and verification details; update both the relevant slice and this index when status changes. A checked box means the stated evidence exists, not that every possible case is solved.

## Status at a glance

| Slice | Status | Remaining work |
| --- | --- | --- |
| [Material ingestion and concepts](backlog/01-material-to-concepts.md) | Complete for the proof-of-concept scope | Broaden classifier evaluation only when new failure cases appear. |
| [Learning path and tutor](backlog/02-path-and-tutor.md) | Complete for the proof-of-concept scope | Fluent-speaker review of Hausa, Yoruba, and Igbo recovery copy remains open. |
| [Quiz, exam practice, and adaptation](backlog/03-quiz-and-adaptation.md) | Built; exam-bank follow-ups remain | A live scanned Physics paper passed Mathpix extraction and single-paper practice, with 11 of 12 questions available and all submitted unscored because there is no verified key. Mixed-paper live submission, marking schemes, official timing/marks matching, written/fill-gap marking, and review of the held OCR candidate remain. |
| [Offline learning and demo](backlog/04-offline-and-demo.md) | Core flow built; acceptance checks remain | Slow-network measurements, session/account transitions, and accessibility completion. |
| [Personal learning guide and clock demo](backlog/05-clock-reading-tutor.md) | Partial demo slice | Rescore against the fresh-account design-pattern and scanned Physics evidence, then complete read-aloud/language and accessibility checks; current rubric 11/14 (pass threshold 12/14). |
| [Accessibility preferences and read-aloud](backlog/06-accessibility-preferences.md) | In progress | Generated audio live-checked in study, guided practice, and timed exam; six routes passed keyboard traversal and native Chrome 200% zoom reflow at effective 320px and 720px widths. Common token contrast pairs passed targeted checks. Real screen-reader/status announcement, physical speaker, exhaustive contrast, and cross-browser checks remain. |
| [After-upload learning choice](backlog/07-post-upload-learning-choice.md) | In progress | Learners can focus the prerequisite-aware path on selected topics from a paper. Incomplete-processing states, route switching, and full accessibility acceptance remain. |

## Prioritized next work

### P0: Prove the core demo end to end

1. **In progress.** A supplied handwritten Physics scan and a CC BY-SA clock worksheet were uploaded in-app and routed through Mathpix. The first Physics attempt failed because the raster page exceeded the useful request size; compressed grayscale rendering now completes the scan and produced 12 indexed questions. The clock worksheet exposed a separate gap: Mathpix returned only the title/site name, no clock-hand readings, yet reported `confidence_rate=1`. A local, clock-specific GD reader is implemented. A fresh live upload proposed all six worksheet readings correctly; the browser required owner review, and only after checking the original did the times enter source evidence. A source-linked concept, tutor check question, guided practice, and post-submission feedback were exercised live. New hand-position questions are labeled supplementary when their geometry was not in the OCR text, and exam-mode validation rejects them. The current demo path was verified live on 2026-10-12: both concepts are about clocks and the recommended next lesson is “Reading times from analog clocks.” Historical records and uploads were preserved. Do not generalize this reader to other diagrams or claim the P0 demo complete yet. Mathpix `/v3/text` does not semantically interpret diagram contents; see the [Mathpix image OCR reference](https://docs.mathpix.com/reference/post-v3-text) and `DEVELOPMENT_LOG.md`.
2. **Partially verified live.** A fresh test learner completed the design-pattern tutor and guided-practice journey. A supplied scanned Physics exam then passed through Mathpix, local relevance classification, concept extraction, question indexing, and one-paper practice. The OCR yielded 12 numbered questions; 11 were ready and the question with a missing option label stayed held for review. Formula choices rendered in the practice view. Submission saved all 11 answers without scoring because the paper had no verified key. This verifies the import/practice loop, not trustworthy scoring or a full Physics study-tutor journey. Slice 5 remains 11/14 until rubric evidence is rescored; accessibility and mixed-paper checks remain open.
3. **In progress.** Live Chrome verified generated audio, pause/resume/stop, and pre-submission answer privacy in study, guided practice, and a timed paper exam. Unsupported Hausa, Igbo, and Yoruba audio shows a plain text-first fallback without a provider request. Six routes passed keyboard traversal and native 200% zoom reflow at effective widths of 320px and 720px. Real screen-reader review, exhaustive contrast audit, physical speaker confirmation, and cross-browser checks remain before closing Slice 6.
4. **Completed: clean-path verification and rubric rescore.** Live review confirmed the recommended lesson in space 47 is clock-related and no generic concept appears in that path. A new design-pattern tutor follow-up and guided practice also raised Slice 5 to 11/14, still below the 12/14 pass threshold. No source files or historical learner records were deleted; the new tutor turn and practice attempt were saved to the existing demo account. The demo is still not accepted; remaining evidence gaps are listed in Slice 5 and the development log.
5. **In progress.** The [Slice 6](backlog/06-accessibility-preferences.md) preference dialog and shared read-aloud controls are implemented. Generated audio played in study, guided practice, and timed paper exam; unsupported-language fallbacks, six-route keyboard traversal, and native 200% browser zoom reflow were checked live. The keyboard sweep also found and fixed the missing focus indicator on single- and mixed-paper practice-time radios. Still open: real screen-reader/status announcement, physical speaker, exhaustive contrast, and cross-browser checks. The standalone clock-practice speech buttons do not satisfy whole-app acceptance by themselves.
6. **In progress.** The [post-upload learning choice](backlog/07-post-upload-learning-choice.md) offers paper-linked topic evidence, ready-question status, exam practice, concept-first study, and a learner-led deterministic recommendation. Learners can choose topics from a paper to focus the prerequisite-aware next step without removing other path concepts. The mixed selector shows topics beside their source papers and requires the learner to confirm the selected set. Incomplete-processing states, broad route-switch checks, and zoom/accessibility acceptance remain open.

### P1: Make imported exam practice trustworthy

1. **Completed for one supplied scanned Physics paper.** The live app used Mathpix, indexed 12 single-choice questions, held one missing-choice OCR candidate for owner review, and completed timed single-paper practice. All 11 submitted answers were correctly left unscored because there was no verified marking key. Test other exam layouts and a multi-page paper next.
2. **Implemented and feature-tested.** Owners can correct uncertain question wording, format, and choices against the original before practice. Corrected questions stay ungraded unless a trusted answer key is available. A live owner correction of the held OCR candidate remains open; the real-paper run is now complete.
3. Match an uploaded marking scheme to the exact paper and question. Never infer an official answer key from an unverified OCR response.
4. Add format-aware marking and worked feedback for written and fill-gap answers, with a clear ungraded state when no trusted key/rubric exists.
5. **Implemented and feature-tested.** Learners can select two or more ready papers in one study space, with source labels preserved through results. A live run with multiple real papers remains untested. Official marks or duration are not inferred; timing remains learner-selected.

### P2: Close low-bandwidth acceptance gaps

1. Exercise a genuinely slow/throttled connection and record transfer bytes and elapsed time.
2. Test session expiry, re-authentication to the same learner, account switching, and private cache clearing.
3. Verify bounded retry/idempotency and complete an offline pack using keyboard-only controls at 200% zoom.

## Working rules

- Keep implementation tasks separate from acceptance evidence; do not mark verification complete based only on unit tests or synthetic browser data.
- Do not weaken source traceability, learner ownership, privacy, or accessibility to make a demo appear complete.
- Reconcile this index against the slice checklists and newest development-log evidence before starting a new backlog item.
- No production launch or scientifically validated mastery claim is implied by this proof of concept.
