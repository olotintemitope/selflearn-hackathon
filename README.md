# SelfLearn
Working name: SelfLearn. Domain-agnostic learning from learner-provided materials.

This is a runnable Laravel monolith proof of concept for turning learner-owned materials into adaptive study.

## Start
1. Use PHP 8.3+ and Node 22.12+ (`nvm use`), then run `composer install` and `npm install`.
2. Copy `.env.example` to `.env`, run `php artisan key:generate`, and configure SQLite or another database.
3. Add local Mathpix and OpenAI credentials to `.env`; never commit real credentials.
4. Run `php artisan migrate`, `npm run build`, and `php artisan serve`.
5. In another terminal run `php artisan queue:work`.

The ingestion flow accepts private image/PDF uploads and retains originals. Image scans and STEM-signalled/unreadable PDF pages use Mathpix; readable non-STEM selectable PDF text uses local extraction. Related pages feed source-linked concepts, with uncertain material held for learner review. New accounts receive one study space automatically.

Repository skills are under .agents/skills. They are project workflow files, not installed personal ChatGPT skills.
Use conventional commits and one focused branch per slice.

Suggested first prompt:
“Read AGENTS.md and docs/backlog/01-material-to-concepts.md. Inspect the application, implement the smallest complete vertical slice, run relevant checks, and report evidence for each acceptance criterion.”

## Recording POC decisions
Use private local storage; AWS credentials and infrastructure are not required. Mathpix and AI calls run server-side and require internet. The browser-side offline flow remains a later backlog slice; see `docs/LOW_BANDWIDTH.md`.

## Existing extraction capabilities
Read docs/EXTRACTION_STACK.md: selectable non-STEM PDF text uses pdftotext; scans and STEM-signalled pages use Mathpix; ImageMagick rasterizes relevant PDF pages. Tesseract is installed but not used in the active OCR route. The other listed packages are optional future capabilities, not newly installed dependencies.
