# Slice 4: Study pack → disconnected learning → sync

**Backlog status:** Core offline flow built; slow-network, session/account, and accessibility acceptance remain. See [the prioritized backlog](../BACKLOG.md).

## Acceptance criteria
- [x] Explicit download.
- [x] text-first pack.
- [x] offline reading and quiz submission.
- [x] pending status.
- [x] reconnect idempotency.
- [x] private cache clearing.
- [ ] accessible mobile demo.

## Out of scope
Offline generative AI.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

## Additional acceptance evidence
- [x] Browser-side reader and quiz work without Livewire/server responses.
- [x] Offline normal reload works after a completed pack download.
- [x] Downloaded packs exclude answer keys and credentials.
- [x] A throttled offline-pack download has measured settings, transferred bytes and duration; cold-versus-warm page-load comparison remains open.
- [x] Offline quiz is visibly pending; reconnect grades and syncs once despite retries.
- [x] New AI generation clearly requires connectivity.
- [ ] Session expiry, same-account reauthentication, account switching, and sign-out cleanup are exercised in a live browser. Automated browser-script tests cover an expired sync response followed by same-account sign-in/sync, account switching, and clearing only the signed-out account's cache. This run also verified deleting the single test pack created for acceptance, but did not sign out or change accounts.

### Live acceptance evidence · 2026-10-13

- Browser: Chrome 155 on macOS, authenticated local app at `127.0.0.1:8015`. The study page opened at 1200×1029 CSS pixels (device-pixel ratio 2). A separate tab was used and closed after testing.
- Download: Chrome DevTools Protocol network emulation set a custom 400 ms latency, 128 kbps downstream and 32 kbps upstream. Keyboard focus reached “Download for offline study” with a visible 3 px green focus ring; Enter started the download. The learner-facing size was 3 KB. The `/spaces/1/offline-pack` resource transferred 3,348 bytes (3,048-byte encoded and decoded body) in 615 ms; the UI reported completion after 673 ms. This is one controlled local request, not a field-network guarantee or cold/warm navigation comparison.
- Offline reload: after the study pack was saved and the reader opened, network emulation was switched fully offline and the offline reader was normally reloaded. Its service worker controlled the page, the saved path and quiz rendered, and `navigator.onLine` was false. The reader reflowed at a 320×844 viewport with client width and document scroll width both 305 CSS pixels. Keyboard Tab reached the pack, each question's radio group, the optional written response and the save button; no answer was selected, typed, or submitted. One offline favicon request failed with `ERR_INTERNET_DISCONNECTED`; the study pack and reader remained usable.
- Cleanup: the current account initially had no downloaded packs. The single test pack was deleted through its page control after reconnecting. The study page returned to “This study path has not been downloaded,” and IndexedDB contained zero packs afterward. No server-side materials, practice runs, or answers were changed. The test tab was closed.
- Automated checks on latest `origin/main`: `node --test tests/JavaScript/offline-study.test.mjs` passed 3/3, covering an expired sync response followed by same-account reauthentication, account switching, and selective sign-out cleanup. `node --check public/js/offline-study.js`, `node --check public/sw.js`, and `git diff --check` passed. These tests use mocked browser storage/network behavior, not a live session-expiry or account-switch flow.
- Still open: live session expiry and same-account sync; live account switching and sign-out cleanup; offline answer submission and idempotent reconnect; 200% browser zoom and screen-reader review; full cold/warm transfer and timing comparison. The isolated latest-main worktree has no `.env` or `vendor/`, so Laravel feature tests were not run. No application defect was found in this acceptance slice.
Read docs/LOW_BANDWIDTH.md before implementation.

## Remaining verification

- Timed Exam mode is deliberately not placed in offline packs; only complete Guided assessments with objective questions and one written-response prompt are currently packaged.
- A single throttled offline-pack download has been measured as recorded above. Cold/warm page navigation comparisons and broader low-bandwidth timing remain open.
- Live session expiry/re-authentication to the same account, switching accounts in one browser, sign-out cleanup, bounded transient retry behavior, and full keyboard-only/200% zoom completion still need dedicated acceptance checks. The offline reader's pack download/open controls and quiz fields were keyboard-tested at 320 CSS pixels; automated tests confirm the reader updates the active account and sign-out deletes only that account's saved packs and answers, preserving other accounts' data.
- The live reconnect check simulated offline state in the browser by toggling `navigator.onLine`; the browser's network stack was not physically disconnected or throttled for that run.
