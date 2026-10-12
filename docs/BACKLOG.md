# Product backlog

Last reconciled: 2026-10-12. This is the canonical priority and status index. The linked slice documents retain the acceptance criteria and verification details; update both the relevant slice and this index when status changes. A checked box means the stated evidence exists, not that every possible case is solved.

## Status at a glance

| Slice | Status | Remaining work |
| --- | --- | --- |
| [Material ingestion and concepts](backlog/01-material-to-concepts.md) | Complete for the proof-of-concept scope | Broaden classifier evaluation only when new failure cases appear. |
| [Learning path and tutor](backlog/02-path-and-tutor.md) | Complete for the proof-of-concept scope | Fluent-speaker review of Hausa, Yoruba, and Igbo recovery copy remains open. |
| [Quiz, exam practice, and adaptation](backlog/03-quiz-and-adaptation.md) | Built; exam-bank follow-ups remain | Server-side expiry and mixed-paper ownership/source preservation are feature-tested; live mixed-paper submission and real-PDF acceptance remain. Other gaps: verified marking schemes, official timing/marks matching, written/fill-gap marking, and source correction for uncertain OCR. |
| [Offline learning and demo](backlog/04-offline-and-demo.md) | Core flow built; acceptance checks remain | Slow-network measurements, session/account transitions, and accessibility completion. |
| [Personal learning guide and clock demo](backlog/05-clock-reading-tutor.md) | Partial demo slice | Fresh-account design-pattern and second STEM acceptance, read-aloud/language verification, accessibility completion; current rubric 11/14 (pass threshold 12/14). |
| [Accessibility preferences and read-aloud](backlog/06-accessibility-preferences.md) | In progress | OpenAI TTS is integrated and live-checked in study; verify actual quiz/exam audio, language support, keyboard, screen-reader, zoom, and cross-browser behavior. |

## Prioritized next work

### P0: Prove the core demo end to end

1. **In progress.** A supplied handwritten Physics scan and a CC BY-SA clock worksheet were uploaded in-app and routed through Mathpix. The Physics page is held for review when `confidence_rate` is missing. The clock worksheet exposed a separate gap: Mathpix returned only the title/site name, no clock-hand readings, yet reported `confidence_rate=1`. A local, clock-specific GD reader is implemented. A fresh live upload proposed all six worksheet readings correctly; the browser required owner review, and only after checking the original did the times enter source evidence. A source-linked concept, tutor check question, guided practice, and post-submission feedback were exercised live. New hand-position questions are labeled supplementary when their geometry was not in the OCR text, and exam-mode validation rejects them. The current demo path was verified live on 2026-10-12: both concepts are about clocks and the recommended next lesson is “Reading times from analog clocks.” Historical records and uploads were preserved. This corrects the earlier note about a generic concept still being recommended first. Do not generalize this reader to other diagrams or claim the P0 demo complete yet. Mathpix `/v3/text` does not semantically interpret diagram contents; see the [Mathpix image OCR reference](https://docs.mathpix.com/reference/post-v3-text) and `DEVELOPMENT_LOG.md`.
2. **Partially verified live on 2026-10-12.** In the existing design-pattern space, a new tutor follow-up cited the uploaded Microsoft reading (pages 15 and 19), then the learner completed guided practice with 3/3 objective answers and 5/5 provisional written feedback. A separate live clock worksheet flow is documented in Slice 5. Still repeat both journeys from a fresh learner account and complete a second STEM source run; do not substitute synthetic rows for this acceptance run.
3. Complete the demo accessibility pass: keyboard-only, 320px reflow, direct 200% browser zoom, and assistive-technology review. Verify OpenAI-generated audio in study, quiz, and exam flows, selected-language text, readable-text fallback, pause/replay behavior, and pre-submission answer privacy.
4. **Completed: clean-path verification and rubric rescore.** Live review confirmed the recommended lesson in space 47 is clock-related and no generic concept appears in that path. A new design-pattern tutor follow-up and guided practice also raised Slice 5 to 11/14, still below the 12/14 pass threshold. No source files or historical learner records were deleted; the new tutor turn and practice attempt were saved to the existing demo account. The demo is still not accepted; remaining evidence gaps are listed in Slice 5 and the development log.
5. **In progress.** The [Slice 6](backlog/06-accessibility-preferences.md) preference dialog and shared OpenAI TTS controls are implemented. A real OpenAI audio response played in the study flow, and assessment/past-paper exam pages render the same controls. A live exam generation attempt returned a learner-safe “could not build a fair exam” message, so actual OpenAI speech playback on a live exam page remains open. Also open: screen-reader review, direct 200% zoom, broad keyboard navigation, and language-quality verification. The standalone clock-practice speech buttons do not satisfy whole-app acceptance by themselves.

### P1: Make imported exam practice trustworthy

1. Upload a real past-paper PDF in-app and complete the exam flow from extraction through submitted results. Record which formats were detected and any review limitations.
2. **Implemented and feature-tested.** Owners can correct uncertain question wording, format, and choices against the original before practice. Corrected questions stay ungraded unless a trusted answer key is available. A live browser correction and real-paper run remain open.
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
