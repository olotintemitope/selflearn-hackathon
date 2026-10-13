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
- [x] Broad study spaces use the first clear upload with source-backed concepts to establish a topic anchor. Later pages are compared with concept names, not descriptions. Only a strong normalized name match enters the study path automatically; partial matches stay reviewable and pages with no name match are kept separate. Later uploads wait for review while an earlier upload is queued or processing; if an earlier upload finishes without concepts, the next clear upload can establish the anchor. Tests cover Physics continuity and Food/Nutrition and ICT pages against a generic goal without a paid classifier call.

## Current limitation

The local topic anchor uses word overlap, not semantic inference. A live one-page Physics and Home Economics comparison kept the food page out of the Physics path, but its partial match was held for learner review rather than confidently marked separate. This is one selected upload pair, not a representative corpus evaluation. A genuinely related new subtopic with little shared wording can be kept out of the current path. Its original upload remains available, and its page can be reviewed.
