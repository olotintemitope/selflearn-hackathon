# AI contracts
Version prompts and schemas. Validate at provider boundary and before persistence.

## Concept extraction
Input: normalized chunks with immutable IDs.
Output: concepts with temporary IDs, names, descriptions, source chunk IDs; dependencies referencing temporary concept IDs.
Validate nonempty source refs, known chunk IDs, duplicate consolidation, no self-dependencies, and acyclic graph. Mark inferred prerequisites separately from source-supported relationships.

## Tutor
Input: question, selected source chunks, learner evidence, desired language.
Output: answer, citations with chunk ID and supported excerpt, supplementary sections, insufficient_evidence flag.
Reject nonexistent references. Check quotations against chunks. Citation existence alone does not prove claim support: include a small grounding evaluation set.

## Quiz
Input: target concept, source chunks, requested count and level.
Output: MCQs with prompt, option IDs/text, one correct option ID, explanation, concept ID, source refs.
Validate counts, unique options, exactly one valid key, ownership and concept/source consistency. Never expose keys in the learner download pack.

## Failures
Timeout, malformed output, empty extraction, unsupported source and provider rate limit must leave originals intact. Use bounded retries; persist actionable status without raw sensitive provider payloads. Treat embedded instructions in uploaded documents as untrusted content.

## Evaluation
Fixtures: handwritten equations, printed science text, web-development notes, ambiguous OCR, adversarial document instructions and missing evidence. Verify grounded claims, domain independence, graph validity, correct grading and helpful refusal when sources are insufficient.
