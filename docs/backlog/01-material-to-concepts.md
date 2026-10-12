# Slice 1: Upload → extraction → visible concepts

**Backlog status:** Complete for the proof-of-concept scope. See [the prioritized backlog](../BACKLOG.md) for current follow-ups.

## Acceptance criteria
- [x] Private image/PDF upload.
- [x] owner-scoped access.
- [x] Material-aware extraction routing and failure states.
- [x] original retained.
- [x] page-linked chunks.
- [x] valid concept output.
- [x] science and technical fixtures.

## Out of scope
Tutor, quiz and mastery.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

- [x] Originals use private local storage and owner-authorized access; no AWS setup is required.
- [x] OCR sends actual bytes through a documented provider-supported flow rather than a localhost URL.

## Existing-stack acceptance criteria
- [x] Read docs/EXTRACTION_STACK.md and inspect existing services before implementation.
- [x] Text PDFs use the existing primary/fallback extraction paths.
- [x] Suitable prose scans can use local OCR; handwriting/equations use Mathpix.
- [x] Mixed pages retain provenance and do not duplicate extracted content.
- [x] Markdown/LaTeX survive normalization, chunking and learner rendering.
- [x] Quality warnings and extractor metadata are persisted; failures retain originals.
