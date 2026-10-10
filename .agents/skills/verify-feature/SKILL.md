---
name: verify-feature
description: Review a project slice against its acceptance criteria. Use before merging or when an independent verification pass is requested.
---

# Workflow
1. Read the issue, AGENTS.md, relevant docs and diff.
2. Trace the actual learner flow and changed behavior.
3. Run appropriate available checks and inspect failure paths.
4. Check authorization, provenance, provider validation and idempotency where relevant.
5. Compare every acceptance criterion with concrete evidence.
6. Report blockers with file locations, reproduction steps and learner impact.
7. State unverified criteria and known limitations explicitly.
Prefer a fresh review context. Do not modify code unless asked to fix findings.
