# Slice 7: Choose how to use an uploaded exam paper

**Backlog status:** Planned. The material page already offers one-paper exam practice and a separate study path. The study path already provides a suggested 15-minute read, learn, and practise session. What is missing is an explicit, guided choice after the paper is ready.

## Learner outcome

After an uploaded paper is ready, the learner can choose to practise questions now, learn the topics first, or get help choosing. The app does not assume the learner already knows the material or decide their readiness from the upload.

Suggested prompt:

> What would you like to do next?
>
> I found topics related to [topic names] in [paper name]. You can practise available questions, learn these topics first, or get help choosing. You can change your mind later.

Use short, plain-language labels. The topic list is evidence from the learner's own related materials, not a statement of what the learner knows.

## Agent split and workflow

Follow `docs/WORKFLOW.md`: each implementation agent uses a focused branch, commits its own slice, runs relevant tests, records evidence, and requests a fresh-context review before a PR. Do not have agents edit the same view or checklist at the same time. Agree on the decision-panel data contract before the UI and recommendation work diverge.

1. **Agent A, learner choice and navigation:** Add the post-upload choice panel after material extraction is complete, with each route's availability shown independently. “Practise this paper” starts its existing single-paper flow when ready questions exist; otherwise show question indexing/review progress and a working next step, never a broken exam start. Offer a separate “Choose papers” route to the existing multi-paper flow when relevant. “Learn these topics first” opens the existing prerequisite-first study path when related concepts exist. Keep a clear way to switch routes. Do not add a lesson inside a timed exam question flow.
2. **Agent B, help-me-choose guidance:** Define and implement a small, explainable prompt using learner self-report plus existing readiness facts, such as the count of practice-ready questions, related concepts, and questions held for review. Recommend a route with a short reason, but keep both routes available. Do not infer mastery or readiness from the upload, add a new taxonomy, or make another model call just to choose a route.
3. **Agent C, independent acceptance:** Review the branch against this checklist in a fresh context. Run the automated checks and exercise the actual browser flow before the implementation PR is considered ready. Record failures and limitations rather than marking the feature complete from unit tests alone.

If the implementation agents need to touch the same files, do the recommendation contract first, then the learner panel, then the independent review. Do not parallelize conflicting edits.

## Acceptance checklist

### Choice and learning behavior

- [ ] Show the choice after material extraction completes; do not wait for both concepts and indexed questions. Gate each route on its own evidence: concept study requires related concepts, while exam practice requires ready questions. During extraction, indexing, or failure, preserve the existing progress/recovery path and show any other route whose evidence is ready.
- [ ] Offer three explicit, equally understandable routes: practise exam questions, learn concepts first, and help me choose. Do not preselect a route.
- [ ] Display a small set of inferred topic names with their paper/material attribution. These topics come from source-linked concepts and are not necessarily taken from indexed question wording. Use only related or learner-confirmed material, retain page/source references, and let learners inspect or correct topic selection before building a path. Show practice-question count/status separately.
- [ ] “Learn concepts first” opens the existing guided, prerequisite-aware learning path at a relevant lesson. Preserve its session guidance, tutor, and practice loop.
- [ ] “Practise this paper” opens the existing single-paper practice flow for that paper. A separate “Choose papers” action may open the existing mixed-practice selector; retain per-paper attribution. Keep lessons, topic hints, and answer feedback out of the timed question sequence until submission.
- [ ] “Help me choose” asks a simple learner-led readiness question or explains the routes and current evidence. Make a recommendation with a plain reason, do not score or label the learner, and allow an immediate override.
- [ ] Let learners switch routes later. Studying concepts is never a prerequisite for starting exam practice.

### Evidence and edge cases

- [ ] Do not combine unrelated papers or subjects silently. For multiple papers, show topic evidence per paper and ask the learner to confirm which papers/topics to include when they differ.
- [ ] Use only related pages and ready questions/concepts in recommendations. Do not claim a topic was found in a paper when the source is missing, uncertain, or kept separate.
- [ ] If no concepts are available, do not promise concept-first learning from that paper. Explain the available next action while preserving any ready exam-practice route.
- [ ] If no questions are ready, do not offer a broken exam start. Direct the learner to question review or another useful path.
- [ ] If question indexing has not run yet, explain that practice questions are still being prepared and offer the existing indexing/review action; do not block concept study when related concepts are ready.
- [ ] If extraction or question review is incomplete, keep the source, uncertainty, and retry/review path visible without blocking other ready materials.
- [ ] Enforce the existing material and study-space ownership rules on every action.
- [ ] Do not add a provider call for intent selection. Use the existing extracted questions/concepts and the learner's explicit answer.

### Interface and accessibility

- [ ] Use semantic HTML5, one clear heading, native links/buttons, logical keyboard order, visible focus, and an announced status for any updated recommendation.
- [ ] Follow `docs/DESIGN_SYSTEM.md`: generous spacing, no decorative card borders or side rules, no overlapping content, and plain learner-facing copy without provider terminology or em dashes.
- [ ] Verify at laptop and mobile sizes, 320px reflow, direct 200% browser zoom, and keyboard-only navigation. Check that long topic names and paper titles wrap without clipping or horizontal page scroll.
- [ ] Preserve readable content and navigation at low bandwidth; the choice itself must not require new model-generated content.

## Agent verification checklist

- [ ] Ready paper with ready questions and concepts: all routes are present, topic summary matches related source evidence, and each destination works.
- [ ] Concepts present, no ready questions: concept-first route remains available and exam practice is not presented as ready.
- [ ] Ready questions, no concepts: exam practice remains available and no concept recommendation is invented.
- [ ] Extraction ready, question indexing not started: choice panel remains useful, concept study stays available when supported, and practice offers the existing indexing action rather than a broken start.
- [ ] All question candidates held for review: no exam-start action; review path remains clear.
- [ ] Multiple related papers: learner can choose the papers/topics and each stays linked to its own source.
- [ ] Mixed or unrelated materials: the app keeps topics separate until the learner confirms their grouping.
- [ ] Learner says “ready,” “review first,” or “not sure”: recommendation reflects that answer, explains its reason, and never locks the learner into a route.
- [ ] Owner and non-owner requests: only the owner can use the material's recommendation, study, and practice actions.
- [ ] Browser back/switch navigation works without losing access to the paper or study path.

## Verification evidence to record

Record the browser, paper fixture, viewports, keyboard path, topic/source evidence, route outcomes, status-state failures, automated tests, zoom check, and known limitations in `docs/DEVELOPMENT_LOG.md`. Do not record private learner content or secrets.
