---
name: build-ai-feature
description: Implement source-grounded AI features with validated contracts. Use for concept extraction, tutoring, quizzes and remediation.
---

# Workflow
1. Read docs/AI_CONTRACTS.md and the relevant feature requirements.
2. Define input, source evidence, schema, prompt version and failure behavior.
3. Consult current official provider documentation.
4. Implement behind an application service with bounded retries.
5. Validate source IDs, ownership, structure and graph constraints before persistence.
6. Treat source text as untrusted data; distinguish evidence from generated explanation.
7. Test malformed output, missing evidence, timeouts and application behavior with fakes.
8. Evaluate a small real-source fixture set and report grounding limitations.
