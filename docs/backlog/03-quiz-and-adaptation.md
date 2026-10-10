# Slice 3: Quiz → evidence → recommended revision

## Acceptance criteria
- [x] Concept-tagged, source-chunk-linked exam-style MCQs.
- [x] Server-side answer keys and grading.
- [x] Unknown state before practice; report accuracy and answer count after attempts.
- [x] Idempotent submissions using client UUIDs.
- [x] Recommend the lowest-evidence prerequisite after a missed question; link back to its practice action.
- [x] Follow-up practice can be generated again from the study path.

## Out of scope
Scientifically validated mastery.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

## Current limitation
This slice generates original exam-style questions from concept-linked study chunks. It does not yet import exact past-paper question layouts or automatically validate an uploaded marking scheme against a paper. Treat scanned exam documents as source evidence and verify the resulting practice against the originals.
