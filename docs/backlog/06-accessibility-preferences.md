# Slice 6: Learner accessibility preferences and read-aloud

**Backlog status:** In progress. The first-use preferences dialog and account-level choice are live-checked. Shared OpenAI read-aloud is implemented and live-checked in study; quiz/exam speech playback and broader accessibility acceptance remain open.

## Goal

Let each learner choose how they prefer to engage with the app, without requiring them to disclose a disability or making one mode mandatory. Keep readable text available throughout the app. Support listening to learning content and keyboard-only use, alongside assistive technologies such as screen readers.

## Acceptance criteria

- [x] Offer a keyboard-accessible first-use preferences dialog with clear choices such as **Listen to content** and **Read on screen**. Explain that the choice can be changed later in settings, and provide a skip/keep-default option. Browser verified first-use display, keyboard selection/save, Escape dismissal, and reopen from the header.
- [x] Persist the learner's preference privately to their account and provide an obvious way to change or reset it. Do not infer or store a diagnosis from the selected experience. Feature tests cover save, change, unsupported values, and guest rejection.
- [x] Keep all learning and exam text available on screen in every preference. Listening is optional and never replaces the readable question, answer choices, instructions, feedback, or source citation. The preference currently does not hide or replace text.
- [x] Provide read-aloud controls for study guides, tutor lessons and replies, assessment questions and choices, exam instructions, and post-submission feedback. Include play/replay, pause/resume, and stop controls with visible state and screen-reader announcements. Show the browser's native audio player after audio is prepared so learners can see progress and adjust playback volume. The shared component requests OpenAI-generated audio only after the learner presses Listen, then privately caches an exact repeat per learner for up to 30 days. Feature tests cover assessment and past-paper exam placement; live browser testing confirmed a real OpenAI audio response starts in the study flow.
- [ ] Make speech respect the selected tutor/content language when supported by OpenAI TTS. If speech or that language is unavailable, explain this plainly and leave the complete readable text usable; do not imply every language is supported. English playback is live-checked; Hausa, Igbo, and Yoruba speech quality and language support remain unverified.
- [ ] Ensure exam read-aloud does not reveal answers before submission, bypass timing, alter responses, or unexpectedly read the answer key. Learners can start, pause, resume, and stop question reading without losing their place or answer. Existing browser inspection confirmed the pre-submission page exposes only question and choices; repeat the full check with OpenAI-generated audio before marking this complete.
- [ ] Provide logical keyboard navigation and visible focus for the preferences dialog, its close/skip/save actions, site navigation, tutor controls, assessment options, exam question navigation, review flags, timer controls, and submission confirmation. Do not require a mouse or drag gesture. A live check found and fixed the missing focus outline on single- and mixed-paper practice-time radios; full keyboard coverage remains open.
- [x] On opening a dialog, move focus into it and keep focus inside until it closes; Escape closes non-critical dialogs, and closing returns focus to the control that opened it. Use semantic dialog markup and announce its name and purpose. The live browser check caught and fixed Tab focus escaping after the last dialog button.
- [ ] Meet WCAG 2.1 AA expectations for semantics, accessible names, focus visibility/order, contrast, status announcements, 320px reflow, and 200% zoom. Do not rely on audio, color, or icons alone to communicate state.
- [ ] Verify in a live browser with keyboard-only navigation and screen-reader checks. Test OpenAI read-aloud playback in both study and quiz/exam flows, including provider failure, pause/replay, and prevention of pre-submission answer disclosure. Record browser, OS, language availability, and limitations. A study lesson returned HTTP 200 from OpenAI and the browser audio element entered playback; this browser view cannot confirm sound from physical speakers.

## Design notes

- Avoid asking learners to identify themselves as blind or deaf. Offer task-oriented preferences instead, such as listening or reading, and support mixed use.
- Read-aloud is a convenience, not a screen-reader replacement. Preserve semantic HTML and standard browser/OS accessibility behavior.
- Use the shared design system for the dialog, spacing, focus treatment, and responsive behavior. Do not use decorative card borders.
- Do not auto-play speech on page load or on every question change. The learner starts playback and remains in control.

## Verification

Automated tests should cover preference persistence/ownership, accessible dialog state, keyboard-operable controls, speech unavailable/error states, and exam answer secrecy. Automated checks do not replace live keyboard, screen-reader, and speech testing.

Current evidence: focused read-aloud, assessment, past-paper, and study/tutor suites pass (43 tests / 350 assertions); full suite: 188 tests / 994 assertions. Pint, JavaScript syntax, `git diff --check`, and production Vite build pass. Live Chrome 154 on macOS made an authenticated OpenAI TTS request from a study lesson, received HTTP 200, and the page reported its audio `playing` event. Assessment and past-paper exam views render the same shared read-aloud controls, and feature tests verify these controls appear without exposing answers before submission. A focused DOM-helper test now verifies narration omits the body of closed disclosures, hidden/inert content, and nested audio controls while retaining visible question text. Run it with `node --test tests/JavaScript/read-aloud-content.test.mjs`; this does not exercise actual audio playback. Actual quiz/exam audio playback still needs its own live browser run. Physical speaker output cannot be verified through this remote browser view. Hausa, Igbo, and Yoruba TTS quality remains unverified; OpenAI voices are AI-generated and optimized for English. Screen-reader review, direct 200% zoom, and broad keyboard navigation remain open.

## Out of scope

- Voice input or speech recognition.
- Claims that AI-generated speech is equivalent to a human narration or is equally available in every language.
