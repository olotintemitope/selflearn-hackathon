# Slice 4: Study pack → disconnected learning → sync

## Acceptance criteria
- [ ] Explicit download.
- [ ] text-first pack.
- [ ] offline reading and quiz submission.
- [ ] pending status.
- [ ] reconnect idempotency.
- [ ] private cache clearing.
- [ ] accessible mobile demo.

## Out of scope
Offline generative AI.

## Verification
Test deterministic behavior and failures with provider fakes; exercise the visible flow and record evidence.

## Additional acceptance evidence
- [ ] Browser-side reader and quiz work without Livewire/server responses.
- [ ] Offline normal reload works after a completed pack download.
- [ ] Downloaded packs exclude answer keys and credentials.
- [ ] Slow-network settings, byte counts and timing observations are recorded.
- [ ] Offline quiz is visibly pending; reconnect grades and syncs once despite retries.
- [ ] New AI generation clearly requires connectivity.
- [ ] Session expiry and user-specific cache clearing are exercised.
Read docs/LOW_BANDWIDTH.md before implementation.
