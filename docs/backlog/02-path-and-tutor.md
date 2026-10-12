# Slice 2: Concepts → path → cited tutor

**Backlog status:** Complete for the proof-of-concept scope. See [the prioritized backlog](../BACKLOG.md) for current follow-ups.

## Acceptance criteria
- [x] Cycle detection.
- [x] ordered prerequisites.
- [x] learner-visible path.
- [x] scoped retrieval.
- [x] resolvable citations.
- [x] insufficient-evidence response.
- [x] provider timeout state.

## Out of scope
Assessment and offline sync.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

## Language recovery evidence

- [x] Empty retrieval saves a next-step response in the selected supported tutor language without calling the tutor provider.
- [x] Citation-validation recovery uses only the selected concept's related, ready source chunks, preserves exact source excerpts, tags the saved assistant turn with its language and concept, and does not retry the provider.
- [x] Unsupported saved language values safely use English for recovery text and turn tags.
- [ ] Fluent speakers review the Hausa, Yoruba, and Igbo recovery wording. Automated tests prove selection and persistence, not translation quality.
