# Architecture
Laravel monolith with Blade/Livewire and queued provider calls. Reuse existing Mathpix configuration; use private local filesystem storage for the recording POC; inspect reusable LearnKasts components before adapting them.

## Ingestion
Private upload → processing job → Mathpix adapter → normalized Markdown with page metadata → chunks → concept extraction → validated dependency graph → learning path.
Use explicit pending/processing/ready/failed states, bounded retries, idempotent jobs, and visible retry controls. Retain originals and extraction versions. Flag uncertain OCR; do not silently correct original notes.

## Tutoring
Retrieve chunks scoped to the learner's learning space. Pass source identifiers and assessed learner evidence. Return validated structured answers with real citations. If evidence is missing, state that and label any supplementary explanation.
For the POC, use a bounded chunk retrieval implementation; a vector database is optional. Never send an entire large library by default.

## Adaptation
Grade MCQs on the server. Append evidence once per attempt. Unknown means unassessed, not 0% ability. Use observed quiz accuracy and evidence counts as a transparent progress heuristic; never claim a validated mastery measurement. Recommend an evidenced weak prerequisite before its dependent concept. Validate graphs for cycles.

## Offline
Download a versioned text-first pack: path, source excerpts, generated explanations and quiz questions. Store locally in IndexedDB; service worker caches the app shell. Queue attempts with UUID idempotency keys. Grade and update authoritative evidence on reconnect. Label pending attempts; do not ship answer keys or API keys. Allow pack deletion and clear local user data on sign-out. Inspect the existing low-bandwidth implementation before building replacements.
