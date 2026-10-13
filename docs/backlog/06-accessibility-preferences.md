# Slice 6: Learner accessibility preferences and read-aloud

**Backlog status:** In progress. The first-use preferences dialog, account-level choice, and generated audio in study, guided practice, and a live timed exam are checked. Unsupported Hausa, Igbo, and Yoruba audio produces a readable fallback without an audio request. Responsive reflow was checked across representative routes at 320px, 720px, and 3840px with enlarged text. True browser zoom, assistive-technology review, and full-app keyboard coverage remain open.

## Goal

Let each learner choose how they prefer to engage with the app, without requiring them to disclose a disability or making one mode mandatory. Keep readable text available throughout the app. Support listening to learning content and keyboard-only use, alongside assistive technologies such as screen readers.

## Acceptance criteria

- [x] Offer a keyboard-accessible first-use preferences dialog with clear choices such as **Listen to content** and **Read on screen**. Explain that the choice can be changed later in settings, and provide a skip/keep-default option. Browser verified first-use display, keyboard selection/save, Escape dismissal, and reopen from the header.
- [x] Persist the learner's preference privately to their account and provide an obvious way to change or reset it. Do not infer or store a diagnosis from the selected experience. Feature tests cover save, change, unsupported values, and guest rejection.
- [x] Keep all learning and exam text available on screen in every preference. Listening is optional and never replaces the readable question, answer choices, instructions, feedback, or source citation. The preference currently does not hide or replace text.
- [x] Provide read-aloud controls for study guides, tutor lessons and replies, assessment questions and choices, exam instructions, and post-submission feedback. Include play/replay, pause/resume, and stop controls with visible state and screen-reader announcements. Show the browser's native audio player after audio is prepared so learners can see progress and adjust playback volume. The shared component requests OpenAI-generated audio only after the learner presses Listen, then privately caches an exact repeat per learner for up to 30 days. Feature tests cover assessment and past-paper exam placement; live browser testing confirmed generated audio playback in both study and guided practice.
- [x] Make speech respect the selected tutor/content language when supported by OpenAI TTS. If speech or that language is unavailable, explain this plainly and leave the complete readable text usable; do not imply every language is supported. English audio is live-checked. Hausa, Igbo, and Yoruba return a clear text-first fallback and do not call the provider. OpenAI's current published supported-language list does not name these three languages, and its voices are optimized for English. This verifies the fallback behavior, not the quality of speech in those languages.
- [x] Ensure exam read-aloud does not reveal answers before submission, bypass timing, alter responses, or unexpectedly read the answer key. Learners can start, pause, resume, and stop question reading without losing their place or answer. In a live timed Physics paper run, generated question audio played for 15.912 seconds; pause, resume, and stop worked, and the timer continued counting down. The attempt remained unanswered and unsubmitted; the pre-submit page exposed no answer key or solution.
- [ ] Provide logical keyboard navigation and visible focus for the preferences dialog, its close/skip/save actions, site navigation, tutor controls, assessment options, exam question navigation, review flags, timer controls, and submission confirmation. Do not require a mouse or drag gesture. A live Tab traversal of one guided-practice page showed no hidden audio players in the tab order and a 3px focus outline on its visible controls; the full route/control matrix remains unverified.
- [x] On opening a dialog, move focus into it and keep focus inside until it closes; Escape closes non-critical dialogs, and closing returns focus to the control that opened it. Use semantic dialog markup and announce its name and purpose. The live browser check caught and fixed Tab focus escaping after the last dialog button.
- [ ] Meet WCAG 2.1 AA expectations for semantics, accessible names, focus visibility/order, contrast, status announcements, 320px reflow, and 200% zoom. Do not rely on audio, color, or icons alone to communicate state. Representative routes reflowed without horizontal overflow at 320px, 720px, and 3840px under a 200% root text-size override; this is not a substitute for true browser zoom.
- [ ] Verify in a live browser with keyboard-only navigation and screen-reader checks. Test read-aloud playback in study, quiz, and exam flows, including provider failure, pause/replay, and prevention of pre-submission answer disclosure. Record browser, OS, language availability, and limitations. In Chrome 154 on macOS, generated audio played in study, guided practice, and a live timed Physics paper; pause, resume, and stop worked. The timed attempt remained unanswered and unsubmitted. A keyboard traversal of one guided-practice route showed visible focus and skipped hidden players, but the full route/control matrix and real screen-reader use are still open. Browser playback events do not confirm sound from physical speakers.

## Design notes

- Avoid asking learners to identify themselves as blind or deaf. Offer task-oriented preferences instead, such as listening or reading, and support mixed use.
- Read-aloud is a convenience, not a screen-reader replacement. Preserve semantic HTML and standard browser/OS accessibility behavior.
- Use the shared design system for the dialog, spacing, focus treatment, and responsive behavior. Do not use decorative card borders.
- Do not auto-play speech on page load or on every question change. The learner starts playback and remains in control.

## Verification

Automated tests should cover preference persistence/ownership, accessible dialog state, keyboard-operable controls, speech unavailable/error states, and exam answer secrecy. Automated checks do not replace live keyboard, screen-reader, and speech testing.

Current evidence: focused read-aloud, assessment, past-paper, and study/tutor suites pass (59 tests / 497 assertions); the read-aloud JavaScript suite passes (2 tests); production Vite build passes with the existing Node 21.7.3 compatibility warning. Live Chrome 154 on macOS played generated audio on a timed Physics paper; the timer continued, pause/resume/stop worked, and the attempt remained unanswered and unsubmitted. Unsupported-language fallback was checked in the browser without a provider request. Materials, study, mixed-paper selection, assessment, timed paper, and clock-practice routes returned successfully and showed no horizontal overflow at 320px, 720px, or 3840px with a 200% root text-size override. This does not establish true browser zoom or full WCAG conformance. A keyboard traversal of one guided-practice page showed visible focus and skipped hidden audio players; the full route/control matrix, real screen-reader use, physical speaker output, and Hausa/Igbo/Yoruba speech quality remain unverified.

## Out of scope

- Voice input or speech recognition.
- Claims that AI-generated speech is equivalent to a human narration or is equally available in every language.
