# Extraction stack
This inventory distinguishes verified capabilities from proposed or reported ones. Inspect `composer.lock`, service implementations, and available binaries before adding dependencies or replacing integrations. Do not describe a package as installed unless it is present in `composer.lock`.

## Verified environment

Verified on 2026-10-10:

| Capability | Verified state | Intended role |
| --- | --- | --- |
| `pdftotext` / Poppler | Installed, version 26.03.0 | Primary local extraction for selectable-text PDFs |
| `pdfinfo` / Poppler | Installed, version 26.03.0 | Inspect PDF metadata and page count before bounded processing |
| `pdfimages` / Poppler | Installed, version 26.03.0 | Detect meaningful embedded page imagery without an AI call |
| `pdftoppm` / Poppler | Installed, version 26.03.0 | Render the document once at low resolution for local visual classification |
| ImageMagick `magick` | Installed, version 7.1.2-9; PDF delegate available | Rasterize scanned PDF pages for OCR |
| Tesseract | Installed, version 5.5.2 | Not used in the current OCR route; do not route STEM scans through it |
| Tesseract language data | `eng`, `osd`, `snum` | English text, orientation/script detection, and mathematical symbols/numerals |
| Mathpix | Configured and live-tested on supplied one-page scans | Canonical OCR for image uploads, scanned pages, handwriting, and pages with STEM signals |

The Laravel application binds `OcrClient` to `MaterialExtractionRouter`. Mathpix is isolated behind `CloudOcrClient`; scanned images and STEM-signalled PDF pages require it. PDF extraction and routing do not call OpenAI. Readable selectable-text pages without detected STEM signals or meaningful diagrams/images use local `pdftotext`. AI is reserved for learner-facing tasks such as study-guide generation, tutoring, quiz creation, marking, and translation. Material relevance is a first-pass local keyword match, not a subject taxonomy. A broad study space uses the first clear upload with source-backed concepts as its topic anchor; later uploads need at least two matching topic terms to enter the path automatically. A later upload waits for review while an earlier upload is queued or processing, while the next clear upload can anchor if an earlier one finishes without concepts. A single shared term stays reviewable, while no shared terms are kept separate. This prevents generic goal words from merging clearly different topics without a paid model call. It remains a lexical heuristic: a new relevant subtopic with little vocabulary overlap can be kept separate, and the original material remains available.

## Composer package status

The following packages were suggested for the wider project but are **not present** in this repository's `composer.json` or `composer.lock`:

| Package | Status | Possible role |
| --- | --- | --- |
| `smalot/pdfparser` | Not installed | Optional in-process PDF text fallback after `pdftotext` |
| `thiagoalessio/tesseract_ocr` | Not installed | Optional PHP wrapper; Symfony Process can invoke the verified binary directly |
| `phpoffice/phpspreadsheet` | Not installed | Future XLSX/CSV quiz import; unrelated to OCR routing |
| `barryvdh/laravel-dompdf` | Not installed | Future PDF export only; never use for extraction |

Do not install a wrapper merely because the underlying binary exists. Prefer the smallest implementation that provides safe argument handling, bounded timeouts, observable failures, and testable contracts. Symfony Process is already available transitively through Laravel and can invoke the verified local tools with argument arrays.

## System tools
- Use `pdftotext -layout` as the primary PDF text extraction path when a usable text layer exists. It is installed and integrated through the material extraction router.
- Use `pdftoppm` to render pages once at 72 DPI for local visual classification. Use ImageMagick at a bounded resolution such as 300 DPI to rasterize only the pages that require OCR. Verify both tools in each deployment environment.
- Tesseract is installed but is not used by the current route. Do not use it as an OCR substitute for STEM notation, diagrams, or handwriting.
- Invoke system tools with Symfony Process argument arrays, bounded timeouts and controlled temporary paths. Apply file/page/resource limits and clean up temporary images.

## Cloud OCR
Mathpix is configured through `MATHPIX_APP_ID` and `MATHPIX_APP_KEY`. The image endpoint is `v3/text`; the PDF endpoint is asynchronous and returns page-linked line data. Full-page image requests enable document layout. Mathpix remains the active OCR provider for STEM/image sources; Tesseract is not an equivalent fallback.
Inspect the existing service and current official documentation before changing requests. Do not assume the image/text endpoint accepts arbitrary multipage PDFs; reuse the supported document flow or page-image flow. Do not print or re-request existing secrets.

## Implemented routing policy
1. For readable selectable-text PDF pages with no detected STEM cues, use `pdftotext -layout`, preserving page boundaries. A separate PHP parser fallback is not currently implemented.
2. Check output quality: nonempty, sufficiently complete, readable and faithful to layout/equations. Successful execution alone is insufficient.
3. Send image uploads through Mathpix with document layout enabled. Do not use Tesseract for scan images, since diagrams or STEM notation can be missed even when ordinary prose looks readable. Rasterize PDF pages sent as images at 220 DPI as 8-bit grayscale PNG with maximum PNG compression. Before upload, bound the base64 data URI to 1,999,000 bytes, below Mathpix's documented 2 MB image limit. If a page exceeds the bound, retry at 200, 180, then 160 DPI; stop with a clear extraction failure rather than sending an oversized image. This normalization applies only to the temporary page image, not the retained original PDF or page provenance.
4. For PDFs, classify each page using local text readability, STEM notation/subject cues, embedded-image geometry, and a rendered visual-residual check. Poppler renders the document once at 72 DPI; the visual check masks Poppler word bounding boxes and measures remaining ink to catch vector diagrams without an AI call. Meaningful embedded figures, STEM cues, unreadable pages, and pages with substantial non-text visuals route to Mathpix. Full-page scans with readable text use a relative per-document outlier check to distinguish a map/diagram page from ordinary scan texture; a readable full-page scan is not sent to Mathpix solely because it has a scan background.
5. For mixed PDFs, route per page. Preserve page numbers and avoid duplicate alternatives. This is a deterministic heuristic, not a trained or universally accurate classifier. It has explicit regression cases for ordinary history prose, STEM terms, raster/embedded visuals, vector diagrams, low-quality text and scan pages with one visual outlier. A local run on the supplied 10-page Nigeria history excerpt routed page 8 to Mathpix for its map and the other nine pages to `pdftotext`; the Mathpix client was faked, so no OCR API call was made. Add representative samples from each deployment domain and review routing before claiming broader accuracy. Fail visibly if PDF layout inspection fails.
6. Use PhpSpreadsheet for XLSX/CSV quiz imports if that feature is implemented. This is an existing capability, not an expansion of the must-have sprint scope.
7. Use Dompdf for export only, never as an extraction service.

The current router implements this policy without additional OCR Composer packages. It imposes bounded process timeouts, a configurable PDF page limit (default 500), and bounded configurable Mathpix PDF polling. `ProcessMaterial` extracts PDFs in resumable page groups (`MATERIAL_PAGE_BATCH_SIZE`, default 5, maximum 10), persists page chunks and a next-page cursor, and queues a continuation. Chunk upserts make a repeated batch idempotent. Relevance review and concept extraction run after all pages have been extracted. The learner sees page-count progress while processing. Within each job, `pdfimages` inspection is further split into process batches (`PDFIMAGES_PAGE_BATCH`, default 10). Each persisted chunk records its extractor, page, warnings, provider request ID when applicable, raw confidence and `confidence_rate` separately when supplied. Mathpix pages with a missing or below-threshold `confidence_rate` remain outside the study guide until the learner compares them with the original. The default review threshold is provisional and requires evaluation against representative local materials. Mixed documents are routed per page without persisting duplicate alternatives.

Mathpix documents `confidence` as a product of per-token OCR confidence and `confidence_rate` as a geometric-mean estimate of output quality. These scores are estimates, can miss omissions and reading-order errors, and need corpus-specific threshold evaluation. Do not use raw `confidence` as a page-level quality percentage. See [Mathpix image-processing response documentation](https://docs.mathpix.com/reference/post-v3-text).

Clock worksheets use a deliberately narrow, local GD-based visual reader after Mathpix OCR. It proposes clock-face bounds and candidate times; this is not a replacement for Mathpix STEM OCR or a general diagram classifier. Every detected clock page is held from the study guide until the learner compares and confirms or edits each time. Persist candidates and the algorithm version separately from OCR. Keep the original Mathpix text unchanged in `locator.ocr_content`; only the confirmed values are added to the source chunk, under an explicit `not Mathpix OCR` label. If AI concept extraction produces an empty graph, a deterministic fallback may provide one source-linked clock concept with supplementary general guidance and the confirmed examples. This proves only the tested analogue-clock shape, not arbitrary visual STEM understanding.

## Persistence and learner experience
Retain originals and extraction versions. Record extractor/provider, source page, language, warnings and any provider confidence that actually exists. Do not invent confidence values or silently rewrite uncertain content.
Preserve Markdown and LaTeX in chunks, prompts, generated questions and source citations. Render math accessibly in the interface; bundle renderer assets locally so downloaded mathematical study packs render offline. Sanitize rendered content and never execute uploaded markup or scripts.

## Verification
Use permission-cleared fixtures: text PDF, scanned prose, handwritten equations, mixed-page PDF, malformed input and provider failure. Verify dispatch, page provenance, equation preservation, authorization and bounded failures with fakes. Verify installed binaries separately. Tests should not assert exact probabilistic OCR text for every real handwritten image.

### Local holdout evaluation, 2026-10-11

The router was run against local PDFs with a fake `CloudOcrClient`; no Mathpix or OpenAI requests were made. Expected routes were assigned by inspecting the source page and whether a selectable text layer existed.

| Sample | Expected route | Observed route | Result |
| --- | --- | --- | --- |
| Ten-page Nigeria history excerpt, pages 1-7 and 9-10 | `pdftotext` | `pdftotext` | 9 correct |
| Same excerpt, page 8 with map | Mathpix | Mathpix | 1 correct |
| Selectable text with a photosynthesis cue | Mathpix | Mathpix | 1 correct |
| Equation image PDF | Mathpix | Mathpix | 1 correct |
| Vector-diagram PDF | Mathpix | Mathpix | 1 correct |
| Six supplied one-page exam scans: literature, home economics, computer studies, and handwritten physics | Mathpix because no selectable text layer is present | Mathpix | 6 correct |

All 19 page decisions matched these expected routes: 10 Mathpix pages and 9 local-text pages. This is a small, selected smoke-test set, not a statistically meaningful accuracy estimate. The six image-only scans validate scan dispatch, not semantic STEM classification. Add a reproducible, permission-cleared corpus with selectable-text STEM pages, image-only ordinary prose, subtle diagrams, and mixed pages before treating the classifier as production-validated. The 10-page history PDF completed local classification in 29.64 seconds on the verified development machine, with Mathpix faked. The document is rendered once at 72 DPI, page text boxes are extracted in one Poppler pass, and residual ink is measured in one ImageMagick invocation. This is an environment-specific measurement; benchmark larger PDFs before raising the page limit or using synchronous processing.

A 50-page slice (pages 100-149) of the 442-page Nigeria country study was used to validate queued extraction batches. The earlier single-job route exceeded the 120-second job budget. With five-page calls to `extractBatch`, all ten groups completed; each took 23.27 to 35.07 seconds, with a combined local run time of 291.42 seconds. Routing selected `pdftotext` for 34 pages and the Mathpix path for 16. Three additional five-page batches from the original 442-page, 30 MB PDF also completed: pages 1-5 in 35.74 seconds, 219-223 in 22.18 seconds, and 438-442 in 34.67 seconds. Mathpix was faked in all checks. These runs exercised real local PDF tools sequentially, not the full Laravel queue/database or subsequent AI relevance and concept calls. A feature test separately verifies cursor persistence, repeated-batch upsert behavior, continuation, finalization and page provenance. The results support the five-page extraction job size for the tested ranges, but do not validate every page of the complete book, live OCR quality, or end-to-end AI stage duration. Upload validation now permits up to 50 MB, subject to PHP and proxy request-size settings. `EXTRACTION_MAX_PDF_PAGES` defaults to 500; the full 442-page route has not been run end to end.

Earlier routing used local Tesseract and then OpenAI visual triage. The current route removes OpenAI from PDF extraction and uses page text, embedded-image geometry, and local visual residuals to select between Mathpix and `pdftotext`. Live Mathpix and relevance tests are recorded above; rerun focused extraction tests after routing changes and continue evaluating against representative mixed STEM and prose PDFs.

### Mathpix page-image payload sizing, 2026-10-13

A supplied controlled comparison on the same Physics scan page found that a 300 DPI RGB PNG (2,317 × 3,425 pixels; 3,897,669 bytes) produced empty text, while a 220 DPI 8-bit grayscale PNG with PNG compression level 9 (1,699 × 2,512 pixels; 1,493,629 bytes) returned 2,845 characters and `confidence_rate=0.993478`. The image endpoint sends PNG bytes as a base64 data URI, so page rendering now starts at the smaller grayscale settings and checks the encoded payload size before calling Mathpix. Oversized pages retry at bounded lower densities and fail closed if none fit. This worktree did not make a Mathpix request; focused behavior is covered with a fake provider.
