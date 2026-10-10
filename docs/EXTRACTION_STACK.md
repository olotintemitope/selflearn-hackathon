# Extraction stack
This inventory distinguishes verified capabilities from proposed or reported ones. Inspect `composer.lock`, service implementations, and available binaries before adding dependencies or replacing integrations. Do not describe a package as installed unless it is present in `composer.lock`.

## Verified environment

Verified on 2026-10-10:

| Capability | Verified state | Intended role |
| --- | --- | --- |
| `pdftotext` / Poppler | Installed, version 26.03.0 | Primary local extraction for selectable-text PDFs |
| `pdfinfo` / Poppler | Installed, version 26.03.0 | Inspect PDF metadata and page count before bounded processing |
| ImageMagick `magick` | Installed, version 7.1.2-9; PDF delegate available | Rasterize scanned PDF pages for OCR |
| Tesseract | Installed, version 5.5.2 | Local OCR for suitable printed prose scans |
| Tesseract language data | `eng`, `osd`, `snum` | English text, orientation/script detection, and mathematical symbols/numerals |
| Mathpix | Configured and live-tested | Handwriting, equations, and material that fails local quality checks |

The Laravel application binds `OcrClient` to `MaterialExtractionRouter`. Mathpix is isolated behind `CloudOcrClient`, allowing local routes to complete without cloud credentials or requests when their output passes quality checks.

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
- Use ImageMagick at a bounded resolution such as 300 DPI to rasterize pages that require OCR. Its PDF delegate is available in the verified environment, but deployment environments must be checked independently.
- Use the Tesseract executable for suitable printed prose. The verified language set is limited; surface unsupported-language states rather than silently producing poor output.
- Invoke system tools with Symfony Process argument arrays, bounded timeouts and controlled temporary paths. Apply file/page/resource limits and clean up temporary images.

## Cloud OCR
Mathpix is reported configured and live using MATHPIX_APP_ID and MATHPIX_APP_KEY. Reported image/text endpoint: https://api.mathpix.com/v3/text. It returns Mathpix Markdown with prose and LaTeX; this is valuable for course/quiz equations that Tesseract can damage.
Inspect the existing service and current official documentation before changing requests. Do not assume the image/text endpoint accepts arbitrary multipage PDFs; reuse the supported document flow or page-image flow. Do not print or re-request existing secrets.

## Implemented routing policy
1. For selectable-text PDFs, try pdftotext -layout, preserving page boundaries; use smalot/pdfparser as an appropriate fallback.
2. Check output quality: nonempty, sufficiently complete, readable and faithful to layout/equations. Successful execution alone is insufficient.
3. For handwriting, equations, mathematical/scientific notation, prefer Mathpix. Do not treat Tesseract as equivalent for equations.
4. For scanned printed prose, use rasterization and local Tesseract where suitable. Offer/retry Mathpix for mathematical or unreliable output; cap automatic cloud retries and surface provider failures.
5. For mixed PDFs, assess and route per page where practical. Preserve page numbers and avoid duplicating text across extraction paths.
6. Use PhpSpreadsheet for XLSX/CSV quiz imports if that feature is implemented. This is an existing capability, not an expansion of the must-have sprint scope.
7. Use Dompdf for export only, never as an extraction service.

The current router implements this policy without additional OCR Composer packages. It imposes bounded process timeouts and a configurable PDF page limit. Each persisted chunk records its extractor, page, warnings, provider request ID when applicable, and provider confidence when supplied. Mixed documents are routed per page without persisting duplicate alternatives.

## Persistence and learner experience
Retain originals and extraction versions. Record extractor/provider, source page, language, warnings and any provider confidence that actually exists. Do not invent confidence values or silently rewrite uncertain content.
Preserve Markdown and LaTeX in chunks, prompts, generated questions and source citations. Render math accessibly in the interface; bundle renderer assets locally so downloaded mathematical study packs render offline. Sanitize rendered content and never execute uploaded markup or scripts.

## Verification
Use permission-cleared fixtures: text PDF, scanned prose, handwritten equations, mixed-page PDF, malformed input and provider failure. Verify dispatch, page provenance, equation preservation, authorization and bounded failures with fakes. Verify installed binaries separately. Tests should not assert exact probabilistic OCR text for every real handwritten image.

On 2026-10-10, automated fakes verified local prose, mathematical fallback, mixed-page routing, PDF fallback, sanitized connection failures, and persisted provenance. A controlled live prose image completed with Tesseract and no cloud request. A controlled two-page mixed PDF used `pdftotext` for selectable prose and Mathpix for a raster equation; both pages retained their source numbers, the cloud page retained its request ID, and the original remained in private storage. Live OpenAI concept extraction produced two source-linked concepts. The learner-facing result was verified in Chrome at desktop and 320px widths.
