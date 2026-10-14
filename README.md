# LearnBloom

Turn the materials you already have into a guided learning journey.

LearnBloom is a domain-agnostic personal learning companion. Learners bring their own notes, books, past papers, technical documents, or other resources. LearnBloom helps organize those materials into a learning path with explanations, practice, feedback, and clear next steps.

## Hackathon challenge focus

LearnBloom primarily addresses **Guidance, Pathways & Opportunity**. It helps learners make progress toward a goal they choose, such as preparing for an exam, understanding a topic, or building a practical skill. Here, opportunity means having useful guidance and a visible next step toward that goal. It does not mean job placement or credential matching.

The project also supports **Access & Discovery of Learning Resources** by bringing a learner's scattered materials together, and **Engagement & Staying in the Learning Journey** through tutoring, practice, progress feedback, and recommended next actions.

## What learners can do

- Create goal-based study spaces and add more materials as they go.
- Turn PDFs and images into source-linked concepts while keeping the original files private.
- Review uncertain material instead of having it silently added to a study path.
- Follow a structured path, ask a tutor questions grounded in their resources, and practise with quizzes or exam questions.
- Get explanations and worked feedback, see areas to revisit, and choose a recommended next step.
- Download a text-first study pack for offline reading and practice. See [the low-bandwidth notes](docs/LOW_BANDWIDTH.md) for what works offline and its current limitations.

The proof of concept uses local extraction for readable, selectable non-STEM PDF text and Mathpix for scans and pages identified as needing visual or STEM-aware OCR. AI supports learning activities, not ordinary PDF text extraction. See [the extraction stack](docs/EXTRACTION_STACK.md) for implementation details.

## Demo journey

Create a learning goal, add a resource, review its extracted concepts, follow the study path, ask a grounded tutor, complete practice, and use feedback to decide what to do next. This demonstrates a continuous learning loop rather than a content library. It is an invention-sprint proof of concept, not a production-ready service.

## Start the application

1. Use PHP 8.3+ and Node 22.12+ (`nvm use`), then run `composer install` and `npm install`.
2. Copy `.env.example` to `.env`, run `php artisan key:generate`, and configure SQLite or another database.
3. Configure local Mathpix and AI-provider credentials in `.env`; never commit real credentials.
4. Run `php artisan migrate`, `npm run build`, and `php artisan serve`.
5. In another terminal run `php artisan queue:work`.

Repository skills are under `.agents/skills`. They are project workflow files, not installed personal ChatGPT skills. Use conventional commits and one focused branch per slice.

Suggested first prompt:

> Read `AGENTS.md` and `docs/backlog/01-material-to-concepts.md`. Inspect the application, implement the smallest complete vertical slice, run relevant checks, and report evidence for each acceptance criterion.

## Recording and proof-of-concept notes

Original uploads use private local storage; AWS credentials and infrastructure are not required. Mathpix and AI calls run server-side and require internet. Offline study packs support selected text-first reading and practice, but provider-dependent actions and some synchronization scenarios still require connectivity. See `docs/LOW_BANDWIDTH.md` for details.

## Existing extraction capabilities

Readable, selectable non-STEM PDF text uses `pdftotext`; scans and STEM-signalled pages use Mathpix; ImageMagick rasterizes relevant PDF pages. Tesseract is installed but not used in the active OCR route. Other listed packages are optional future capabilities, not newly installed dependencies. See `docs/EXTRACTION_STACK.md`.
