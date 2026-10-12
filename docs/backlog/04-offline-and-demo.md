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
- [ ] Slow-network settings, byte counts and timing observations are recorded.
- [x] Offline quiz is visibly pending; reconnect grades and syncs once despite retries.
- [x] New AI generation clearly requires connectivity.
- [ ] Session expiry and user-specific cache clearing are exercised.
Read docs/LOW_BANDWIDTH.md before implementation.

## Remaining verification

- Timed Exam mode is deliberately not placed in offline packs; only complete Guided assessments with objective questions and one written-response prompt are currently packaged.
- Slow-network throughput, transferred-byte, and duration measurements remain pending.
- Session expiry/re-authentication to the same account, switching accounts on one browser, bounded transient retry behavior, and keyboard-only/200% zoom completion still need dedicated acceptance checks.
- The live reconnect check simulated offline state in the browser by toggling `navigator.onLine`; the browser's network stack was not physically disconnected or throttled for that run.
