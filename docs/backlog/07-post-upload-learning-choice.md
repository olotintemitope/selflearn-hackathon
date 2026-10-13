# Slice 7: Choose how to use an uploaded exam paper

**Backlog status:** In progress. The ready-paper page offers practice, concept-first study, and help-me-choose routes. Learners can now choose paper-linked topics to focus on in the prerequisite-aware path; mixed practice displays source-linked topics for each paper and requires explicit confirmation before starting. Incomplete extraction/retry review, full route-switch acceptance, broad keyboard testing, and direct 200% zoom remain open.

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

- [x] Show the choice after extraction completes without waiting for question indexing. Gate concept study on related concepts and practice on ready questions. Pending extraction preserves the existing progress view; no-concept, no-indexing, held-question, and non-paper states are covered by feature tests.
- [x] Offer distinct practice, concept-first study, and help-me-choose routes without preselecting one. The recommendation form is collapsed until requested.
- [x] Display source-linked topic names, page references, excerpts, and practice status. Learners can inspect evidence and choose one or more current paper topics before opening the path. The full learning path remains available; selected topics guide the next step and their required prerequisites are included first. This is a focus choice, not a destructive edit to the space's curriculum.
- [x] “Learn these topics first” opens the existing guided study path at the selected source-linked concept; the live browser confirmed the route and study page.
- [ ] “Practise this paper” opens the existing single-paper practice flow for that paper. A separate “Choose papers” action may open the existing mixed-practice selector; retain per-paper attribution. Keep lessons, topic hints, and answer feedback out of the timed question sequence until submission.
- [x] “Help me choose” asks what the learner wants today, gives a deterministic plain-language recommendation, and keeps an immediate alternate route. A live submission of “I am not sure yet” returned study and practice actions.
- [x] Route switching remains available from the paper page. Concept study is not a prerequisite for practice.

### Evidence and edge cases

- [x] Do not combine unrelated papers or subjects silently. The mixed selector shows current source-linked topics and pages per paper, permits the learner to remove papers, and requires explicit confirmation before combining the selected papers, even when topics differ.
- [x] Use only related pages and ready questions/concepts in the panel and recommendation. Source excerpts and topic page references exclude stale extraction versions; a regression test covers stale evidence.
- [x] If no concepts are available, do not promise concept-first learning; feature tests cover preserving ready practice and the no-concept recommendation.
- [x] If no questions are ready, do not offer a broken exam start; held-question tests confirm the review path remains available.
- [x] If question indexing has not run yet, the panel keeps concept study available and links to the visible “Find questions” action.
- [ ] If extraction or question review is incomplete, keep the source, uncertainty, and retry/review path visible without blocking other ready materials.
- [x] The material page uses existing ownership authorization; a feature test covers a non-owner request.
- [x] Intent selection uses a deterministic local guide with no provider call.

### Interface and accessibility

- [x] Uses a native disclosure, fieldset/radios, links, buttons, and an announced recommendation status.
- [x] Follows the design system's borderless surfaces, spacing, and plain learner-facing copy.
- [ ] Verify keyboard-only navigation with visible focus, direct 200% browser zoom, and a full laptop/mobile browser pass. The live 320px check found no horizontal overflow.
- [x] The choice and recommendation work locally without generated content or another model request.

## Agent verification checklist

- [x] Ready paper with ready questions and concepts: all three choices are shown and the help-me-choose-to-study destination works in the live browser. The practice action leads to the existing practice form.
- [x] Concepts present, no ready questions: concept-first route remains available and exam practice is not presented as ready.
- [x] Ready questions, no concepts: exam practice remains available and no concept recommendation is invented.
- [x] Extraction ready, question indexing not started: the choice remains useful, concept study stays available when supported, and finding questions remains visible.
- [x] All question candidates held for review: no exam-start action; review path remains clear.
- [x] Multiple related papers: learner can select papers and inspect distinct topic/page evidence per paper before confirming the set.
- [x] Mixed or unrelated materials: distinct topics remain attributed to their papers and the learner must explicitly confirm the selected set before practice starts.
- [x] Learner intent produces a reasoned recommendation and does not lock the learner into a route. Automated tests cover intent outcomes; the live browser covered “not sure.”
- [x] Owner and non-owner access to the material page is covered by feature tests; destinations retain their existing ownership checks.
- [ ] Verify browser back and route switching across practice, mixed practice, and study destinations.

## Verification evidence to record

Record the browser, paper fixture, viewports, keyboard path, topic/source evidence, route outcomes, status-state failures, automated tests, zoom check, and known limitations in `docs/DEVELOPMENT_LOG.md`. Do not record private learner content or secrets.
