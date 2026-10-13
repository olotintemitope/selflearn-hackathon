# SelfLearn design system

Use this document for every learner-facing screen. The canonical implementation is `resources/css/app.css`; update this document whenever the shared visual language changes.

## Direction

SelfLearn should feel like a calm, trustworthy study desk rather than a generic dashboard. The interface is text-first, evidence-forward, and comfortable on a low-cost mobile device.

- Use warm paper surfaces, deep green text, restrained shadows, and generous whitespace.
- Use editorial serif type for orientation and hierarchy; use the system sans stack for controls and reading.
- Keep source evidence visually distinct from generated explanations and supplementary knowledge.
- Prefer clear states and plain language over decorative UI.
- Do not add runtime font, icon, or image CDN dependencies. The offline shell must remain self-contained.

### Learner-facing copy

- Write for learners and the people supporting them, not developers. Use short, familiar words and explain what happened or what to do next.
- Keep implementation details out of the interface. Do not use terms such as cache, OCR, API, endpoint, model, token, queue, worker, or provider in learner-facing text. Describe the benefit or action in plain language instead.
- Keep necessary AI and privacy notices concise and understandable. Explain what the learner selected and what the app does with it, without implementation detail.
- Do not use em dash punctuation in learner-facing or generated text.

## Typography

### Families

| Role | Stack |
| --- | --- |
| Display and headings | `ui-serif, Georgia, Cambria, "Times New Roman", serif` |
| Body, labels, and controls | `ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif` |

### Hierarchy

| Level | Use | Rule |
| --- | --- | --- |
| Display | Primary learner outcome or page promise | `clamp(2.75rem, 7vw, 5rem)`, line-height `.98` |
| Page title | One `h1` per page | `clamp(2.25rem, 5vw, 3.75rem)`, line-height `1.02` |
| Section title | Major content groups | `clamp(1.5rem, 3vw, 2rem)`, line-height `1.12` |
| Card title | Concept or material title | `1.3rem`, line-height `1.2` |
| Lead | One-sentence context below a title | `1.05–1.15rem`, line-height `1.55–1.65` |
| Body | Explanations and evidence | `1rem`, line-height `1.55` |
| Label | Form and metadata labels | `.88rem`, bold, slight positive tracking |
| Eyebrow | Short category above a title | `.75rem`, uppercase, bold, `.14em` tracking |

Headings describe the page before controls appear. Never use font size alone to imitate a heading; use semantic `h1`–`h3` elements. Keep headings short and sentence-cased.

## Color

| Token | Value | Use |
| --- | --- | --- |
| Ink | `#16302b` | Body text |
| Heading ink | `#102d27` | Headings |
| Primary | `#176b55` | Primary actions and links |
| Primary hover | `#0f5846` | Hover state |
| Supporting text | `#58706a` | Lead and secondary copy |
| Rust | `#a34f2b` | Eyebrows and restrained emphasis |
| Canvas | `#f4f0e8` | Page background |
| Paper | `#fffdf8` | Cards and panels |
| Border | `#d8d0c2` | Structural borders |
| Warning | `#d7a72d` | Low confidence and pending attention |
| Danger | `#b93d31` | Errors and destructive warnings |
| Ready | `#cce8d8` | Successful processing |
| Failed | `#ffd1ca` | Failed processing |
| Processing | `#f8df9d` | Pending or processing state |

Maintain readable contrast. Do not communicate status by color alone: pair color with a visible text label.

## Spacing and layout

Use a four-pixel base rhythm. Prefer `0.5rem`, `0.75rem`, `1rem`, `1.5rem`, `2rem`, `3rem`, and `5rem` steps.

- Use at least `1.5rem` between separate question surfaces, `1.25rem` between a prompt, its source context, and its answers, and `0.75rem` between answer options. Keep at least `1.5rem` between the final question and the primary submit button.
- In a question bank, group each question in a padded, borderless paper surface. Separate the heading/status, metadata, prompt, choices, source disclosure, and practice action with a consistent `1rem` grid gap; keep at least `1.5rem` between question surfaces. Keep metadata readable and the prompt line length bounded. Never use full-width action buttons for a single question unless the learner is in a focused full-exam flow.
- Material titles use the page-title scale, even when the uploaded filename is long. Wrap at word boundaries and keep the title block to a comfortable reading width instead of using display-size typography for filenames.
- Render OCR answer blanks as visible underscores or a clearly spaced blank, never as raw LaTeX escape sequences such as `\\_\\_`.
- On a single-question practice page, keep the paper name, question number, page note, prompt, source detail, answer controls, and submit action as distinct blocks. Use at least `0.75rem` between title metadata and `1.25rem` around the question and answer regions; prevent a long paper title or prompt from forcing horizontal overflow.
- Keep a question's prompt directly followed by its answer choices. Put paper and page attribution after the answer choices, never between the question and its answers.
- Do not use em dash punctuation in learner-facing or generated text. Prefer a period, colon, comma, or parentheses.

- Page content is capped at `70rem` with at least `1rem` side gutters.
- Reading and hero copy should not exceed about `50rem`; supporting prose should stay near `42rem`.
- Authentication forms are single-purpose and capped at `31rem`.
- Cards use `1.5rem` padding; high-focus forms may grow to `2.5rem` on wide screens.
- Use grid gaps instead of margins between repeated items.
- At `700px` and below, multi-column forms collapse to one column and card padding tightens.
- Adjacent page regions retain at least `1.5rem` separation; content must remain in normal document flow and never overlap.
- Flexible action and metadata rows wrap when space is limited. Text may wrap but must not be clipped or force horizontal scrolling.
- At 320 CSS pixels and 200% zoom, controls, cards, headings, status messages, and source evidence retain readable spacing and usable padding.
- The shared container and header grow at 1440px and 2560px breakpoints, with a bounded maximum width for readable line lengths on 4K screens. Core learner pages must be checked from 320px through 3840px, not only at one mobile and one desktop width.

## Components

### Cards

Cards use surface color, spacing, radius, and restrained shadow for grouping. Never add a border as card decoration. Do not use decorative left- or right-edge rules on cards, notices, errors, messages, or source excerpts; use surface colors, semantic labels, spacing, and restrained shadows instead. Borders remain appropriate for controls and focus indicators where they communicate interaction.

### Asynchronous status

Describe the learner outcome in plain language, such as “Preparing your study guide,” rather than exposing implementation terms such as queues, workers, jobs, terminal commands, or provider operations.

- Update the page automatically while work is pending; do not require manual refresh.
- Use `role="status"` with `aria-live="polite"` for non-urgent progress changes.
- Tell learners when they may safely leave and return later.
- Provide an actionable retry control only after a failure.
- Keep operational setup and debugging instructions in developer documentation and logs, never in learner-facing status cards.

### Header

Use the serif SelfLearn wordmark followed by a small green robot icon on the left and one compact session action on the right. Keep the icon decorative and hidden from assistive technology so the linked brand is announced once. Keep the header quiet so learner content remains dominant.

### Hero

Order content as eyebrow → `h1` → concise lead. Use a display-sized title only for the primary promise or outcome, not every page.

### Forms

- Login and registration remain separate pages.
- Put a visible label above every control; placeholders never replace labels.
- Inputs and primary buttons have a minimum height of `3rem`.
- Use the green focus ring defined in the shared CSS.
- Put one primary action at the end of the form and a reciprocal text link below it.
- Show validation errors together near the top and associate field-specific errors when forms become more complex.

### Practice questions

Use native `fieldset`, `legend`, and labelled radio inputs for multiple-choice questions. Put the question surface on a normal-flow parent around the fieldset so browser legend rendering cannot paint outside the visible surface. Give the prompt, source context, options, each question, and the submit action clear separation; give each answer a generous click/touch area and clear hover, focus, and selected states without replacing the native control. Keep option labels easy to scan and preserve a strong prompt-to-answer hierarchy. Name the exact source material and page beside every question. Label supplementary questions as going beyond the learner's material and explain that they use standard subject knowledge to explore the topic further. Long prompts must remain inside the question surface and reflow at 320px.

Render delimited LaTeX in learner-facing explanations, tutor responses, and study concepts with the locally bundled KaTeX assets. Keep delimiters in generated content as `\(...\)` inline or `\[...\]` display math. Do not interpret arbitrary unmarked prose as formula source. Preserve MathML output for assistive technology and contain long display equations on narrow screens.

After submission, show the correct option, a short explanation, and an ordered worked solution for each practice question. Quantitative solutions make the formula, substitution, units, and final result easy to follow. Keep answer keys, solution steps, and written model answers hidden before submission. Use a borderless, padded solution section with clear step spacing.

### Buttons and links

Use filled green buttons for the primary action. Use normal underlined text links for navigation and evidence access. Avoid multiple equally prominent buttons in one region.

### Status and feedback

Use explicit `pending`, `processing`, `ready`, and `failed` labels. Use distinct, accessible surface colors and actionable recovery copy for notices and errors, never decorative left- or right-edge rules. Status messages should use appropriate live-region semantics.

### Study path and tutor

On wide screens, keep the prerequisite-first path in the main column and the grounded tutor in a right-hand chat panel. Keep the panel in normal page flow with a sticky desktop position only when it does not obscure content; make the conversation area independently scrollable. At tablet and mobile widths, stack the tutor below the path, remove sticky positioning, and preserve readable spacing and keyboard order.

Tutor answers support safe Markdown for emphasis, ordered and unordered lists, blockquotes, and code. Render through the shared learner Markdown component; strip raw HTML and disallow unsafe link schemes. Keep generous line height, list indentation, and spacing between adjacent blocks so longer answers remain readable in the chat panel without horizontal overflow. When a “Recommended next” eyebrow precedes actions, keep at least `1rem` of vertical separation so the label does not crowd the buttons.

### Evidence

Source excerpts use a blockquote-like paper inset with a distinct surface and an exact locator such as page or section. Do not add a left- or right-edge rule. Generated explanation and supplementary knowledge must have separate headings and must not visually merge with quoted evidence.

## Content rules

- Lead with what the learner can do next.
- Prefer short sentences and familiar words.
- Use “source,” “page,” “concept,” and “evidence” consistently.
- Never imply OCR certainty or mastery when evidence is incomplete.
- Error messages state what happened, what was preserved, and the next available action.

## HTML5 implementation baseline

All learner-facing markup must use semantic HTML5 and remain understandable before CSS or JavaScript enhancement.

- Start documents with `<!doctype html>`, UTF-8 metadata, a responsive viewport, a meaningful `<title>`, and the correct `lang` attribute.
- Use `header`, `nav`, `main`, `section`, `article`, `aside`, and `footer` according to meaning, not as styling hooks. Every `section` should normally have a heading.
- Use native `button` elements for actions and `a` elements with real `href` values for navigation. Do not make clickable `div` or `span` controls.
- Use `form`, `label`, `fieldset`, `legend`, appropriate input types, `autocomplete`, and native validation attributes. Server-side validation remains authoritative.
- Use `details` and `summary` for simple disclosures, such as source evidence, when their native behavior meets the interaction need.
- Use lists for collections and tables only for tabular relationships. Tables include header cells and captions where a visible or programmatic caption adds context.
- Images declare intrinsic dimensions when known to reduce layout shift. Use `figure` and `figcaption` when an image, diagram, or excerpt needs a caption.
- Preserve meaningful Mathpix Markdown and LaTeX as source data; expose an accessible text equivalent when rendered math or diagrams are added.
- Keep DOM order equal to the intended reading and keyboard order. Do not use CSS ordering to create a conflicting visual sequence.
- JavaScript enhances server-rendered content; it must not be the only way to reach essential learning content unless the feature is explicitly an offline browser flow.
- Avoid obsolete or presentational markup such as `font`, `center`, layout tables, or empty heading elements.
- Keep element IDs unique, quote attributes, close elements correctly, and validate representative pages when an HTML validator is available.
- Do not place secrets, provider keys, answer keys, private paths, or raw provider payloads in HTML, data attributes, or browser bundles.

## Accessibility: WCAG 2.1 Level AA

Every learner-facing screen must target WCAG 2.1 Level AA. Treat accessibility as acceptance criteria, not optional polish. Do not claim conformance from automated checks alone; combine automated checks with keyboard and browser inspection.

### Structure and language

- Set the page language and use semantic landmarks: `header`, `nav`, `main`, and `footer` where applicable.
- Use one descriptive `h1` and an ordered heading outline without skipping levels for visual effect.
- Use native buttons, links, form controls, lists, tables, and disclosure elements before adding ARIA.
- Give every page a unique, descriptive title and every meaningful image useful alternative text. Decorative images use empty alt text.
- Link text must make sense out of context; avoid “click here.”

### Keyboard and focus

- Every action must work with keyboard alone and follow a logical focus order.
- Provide a visible focus indicator for every interactive element. Never remove the browser outline without an equally visible replacement.
- Use the shared `3px` solid `#176b55` focus outline with a `3px` offset; do not suppress it for form controls. Interactive boundaries and the focus ring must retain at least `3:1` contrast against adjacent surfaces.
- Focus must not become trapped except inside a deliberate, dismissible modal.
- When validation fails, move focus to or clearly announce the error summary. When a modal opens, move focus into it and restore focus when it closes.
- Include a skip link when a page gains repeated navigation beyond the current compact header.

### Contrast and non-color cues

- Normal text requires at least `4.5:1` contrast; large text requires at least `3:1`.
- Controls, focus indicators, icons, and meaningful graphical boundaries require at least `3:1` against adjacent colors.
- Never communicate status, correctness, required fields, or errors by color alone. Pair color with text and, when helpful, shape or iconography.
- Check every new token pairing before adding it to the shared palette.

### Forms, errors, and status

- Every control has a persistent programmatic label; placeholders only provide examples.
- Group related controls with `fieldset` and `legend`.
- Identify required inputs in text and code. Preserve entered values after validation failure, except secrets when unsafe.
- Associate field errors using `aria-describedby` and set `aria-invalid="true"` on invalid controls.
- Error summaries explain the issue and link to the affected fields.
- Use `role="status"` or `aria-live="polite"` for nonurgent processing updates. Use `role="alert"` only for urgent failures.
- Loading states expose readable text and do not rely on an animated spinner alone.

### Reflow, zoom, and targets

- Content must reflow without loss or two-dimensional scrolling at `320px` CSS width, except intrinsically two-dimensional content such as a necessary data table or diagram.
- Support text resize to `200%` without clipping, overlap, loss of controls, or horizontal page scrolling.
- Do not lock viewport zoom or use fixed heights for text containers.
- As a stronger SelfLearn convention, interactive targets should be at least `44×44` CSS pixels where practical, even though this exceeds the WCAG 2.1 AA minimum.
- Provide enough spacing to avoid accidental activation on small screens.

### Media, motion, and time

- Respect `prefers-reduced-motion` before adding nonessential motion.
- Avoid autoplay. Any time limit must be avoidable, adjustable, or extendable unless essential.
- Video requires captions; audio-only content requires a transcript; meaningful visual instruction needs an equivalent description.
- Do not flash content more than three times per second.

### Testing evidence

- Test at a minimum of `390×844` mobile and `1200×900` desktop, plus a `320px` reflow check and `200%` zoom check.
- Keyboard-test the full task using `Tab`, `Shift+Tab`, `Enter`, `Space`, arrow keys where expected, and `Escape` for dismissible layers.
- Verify registration, login, upload, status, evidence, retry, download, and sign-out with a live browser.
- Run an automated accessibility scan when the project adds an approved scanner, but manually verify labels, heading order, focus, announcements, and reading sequence.
- Record browser, viewport, keyboard path, automated results, failures, and limitations in `docs/DEVELOPMENT_LOG.md`.
- Treat a clean browser console and successful local asset loading as supporting evidence, not accessibility proof.

## New-screen checklist

- [ ] The screen has one clear learner task and one `h1`.
- [ ] Markup uses semantic HTML5 and native controls before ARIA or custom interaction code.
- [ ] Typography follows the hierarchy above.
- [ ] Source evidence, generated content, and supplementary knowledge are distinguishable.
- [ ] Loading, empty, success, failure, and retry states are represented where applicable.
- [ ] Ownership-sensitive actions are tested as owner, guest, and another learner.
- [ ] Keyboard focus, labels, contrast, and status semantics are checked.
- [ ] The screen is keyboard-tested and browser-tested at mobile, desktop, `320px` reflow, and `200%` zoom.
- [ ] WCAG 2.1 AA contrast, form errors, status announcements, and non-color cues are verified.
- [ ] Shared styles are reused before adding a new component rule.
- [ ] Any intentional design-system change updates this document and `resources/css/app.css` together.
