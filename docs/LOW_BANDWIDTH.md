# Low-bandwidth implementation and proof
## Capability boundary
Online: initial upload, OCR, new AI generation, server grading and authoritative progress updates.
Offline after explicit download: app shell, text lessons, source excerpts, existing explanations, quiz questions and queued answers. Unknown or pending results stay labeled until server sync.

## Implementation requirements
- Service worker caches a locally served static app shell and offline navigation fallback. Laravel/Livewire network requests cannot be assumed to work offline: implement a browser-side study reader and quiz flow backed by IndexedDB.
- Version study packs and store by user and learning space. Do not indiscriminately cache authenticated responses or sensitive API responses.
- Download text first. Load originals and large source images only on request; handle unavailable source images clearly offline.
- Keep quiz keys and provider credentials server-only. Pending answers show “Saved on this device · Awaiting sync”.
- Queue attempts with stable UUIDs. Enforce a server uniqueness constraint per user/attempt UUID. Retry transient errors with bounded backoff; retain pending items until acknowledged. Provide manual Sync now because background sync is not universal.
- On reconnection, authenticate and grade the server-held quiz version, append evidence once, and update authoritative progress. An expired session must preserve pending work until reauthentication to the same account.
- Clear user-specific packs and pending data on sign-out with a warning for unsynced work; never expose one user's packs to another. Offer Delete downloaded pack.

## Recording checklist
1. Online: upload and process a real image/PDF; generate a learning path and explanations.
2. Select Download for offline study. Show successful completion and pack size.
3. Keep DevTools Network visible; enable throttling using an available slow preset (Slow 3G if present) or documented custom settings. Record exact throughput and latency.
4. Compare cold online load with warm pack navigation; record transferred bytes and timings. Do not invent bandwidth savings or performance claims.
5. Select Offline, use a normal reload, then open downloaded lessons and prior explanations. Verify the app did not rely on a live Laravel response.
6. Submit a downloaded quiz; show pending sync and no claimed server grade.
7. Reconnect, sync, and show the stored attempt plus updated evidence.
8. Retry the same submission; verify there is still exactly one stored attempt/evidence set.
9. Show a new AI request is unavailable offline, with a useful explanation.

## Evidence log
| Scenario | Network settings | Transferred bytes | Measured duration | Outcome |
| --- | --- | --- | --- | --- |
| Cold online load | To measure | To measure | To measure | Pending |
| Warm study-pack navigation | To measure | To measure | To measure | Pending |
| Offline reload/read | Chrome 154 CDP offline emulation after pack download | 20 KB pack saved before going offline; reload transfer not separately measured | Not measured | Existing IndexedDB pack reopened with network offline. The study path and section heading rendered; raw `\\section*{...}` markup was not shown. The browser's automatic favicon request failed because the workspace favicon is empty. This pack had no saved quizzes or tutor explanations. |
| Queued quiz and reconnect | Offline then online | To measure | To measure | Pending |

Throttling demonstrates behavior under a controlled simulated connection. It does not establish field performance on every basic device or network.

## Mathematical materials
KaTeX and its fonts are bundled with the Vite app assets. Mark learner-facing math text for local LaTeX rendering and verify a downloaded LaTeX explanation renders after an offline reload without CDN requests.
