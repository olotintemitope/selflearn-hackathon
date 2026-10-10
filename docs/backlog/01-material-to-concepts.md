# Slice 1: Upload → extraction → visible concepts

## Acceptance criteria
- [ ] Private image/PDF upload.
- [ ] owner-scoped access.
- [ ] Mathpix extraction and failure states.
- [ ] original retained.
- [ ] page-linked chunks.
- [ ] valid concept output.
- [ ] science and technical fixtures.

## Out of scope
Tutor, quiz and mastery.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

- [ ] Originals use private local storage and owner-authorized access; no AWS setup is required.
- [ ] OCR sends actual bytes through a documented provider-supported flow rather than a localhost URL.
