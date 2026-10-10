# SelfLearn design system

Use this document for every learner-facing screen. The canonical implementation is `resources/css/app.css`; update this document whenever the shared visual language changes.

## Direction

SelfLearn should feel like a calm, trustworthy study desk rather than a generic dashboard. The interface is text-first, evidence-forward, and comfortable on a low-cost mobile device.

- Use warm paper surfaces, deep green text, restrained shadows, and generous whitespace.
- Use editorial serif type for orientation and hierarchy; use the system sans stack for controls and reading.
- Keep source evidence visually distinct from generated explanations and supplementary knowledge.
- Prefer clear states and plain language over decorative UI.
- Do not add runtime font, icon, or image CDN dependencies. The offline shell must remain self-contained.

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

- Page content is capped at `70rem` with at least `1rem` side gutters.
- Reading and hero copy should not exceed about `50rem`; supporting prose should stay near `42rem`.
- Authentication forms are single-purpose and capped at `31rem`.
- Cards use `1.5rem` padding; high-focus forms may grow to `2.5rem` on wide screens.
- Use grid gaps instead of margins between repeated items.
- At `700px` and below, multi-column forms collapse to one column and card padding tightens.

## Components

### Header

Use the serif SelfLearn wordmark on the left and one compact session action on the right. Keep the header quiet so learner content remains dominant.

### Hero

Order content as eyebrow → `h1` → concise lead. Use a display-sized title only for the primary promise or outcome, not every page.

### Cards

Use paper background, one-pixel border, `1rem` radius, and a restrained green-tinted shadow. A card must group related information; do not wrap every line in a card.

### Forms

- Login and registration remain separate pages.
- Put a visible label above every control; placeholders never replace labels.
- Inputs and primary buttons have a minimum height of `3rem`.
- Use the green focus ring defined in the shared CSS.
- Put one primary action at the end of the form and a reciprocal text link below it.
- Show validation errors together near the top and associate field-specific errors when forms become more complex.

### Buttons and links

Use filled green buttons for the primary action. Use normal underlined text links for navigation and evidence access. Avoid multiple equally prominent buttons in one region.

### Status and feedback

Use explicit `pending`, `processing`, `ready`, and `failed` labels. Notices use a gold left border; errors use a red left border and actionable recovery copy. Status messages should use appropriate live-region semantics.

### Evidence

Source excerpts use a blockquote-like paper inset with a gold rule and an exact locator such as page or section. Generated explanation and supplementary knowledge must have separate headings and must not visually merge with quoted evidence.

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
