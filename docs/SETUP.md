# Local recording setup
1. Create or use a Laravel application; preserve its generated .env and APP_KEY.
2. Merge .env.hackathon.example settings into .env. Reuse the already configured Mathpix app ID/key; add missing credentials only in an environment where they are absent. Set your chosen AI provider credentials locally. The OpenAI fields are the default proposal; adapt for another provider.
3. Wire proposed environment variables into Laravel config and read configuration from services. Verify current official provider documentation before implementing API requests. Do not assume these variables configure an integration automatically.
4. Use a private local disk for originals. Inspect its actual configured root; serve through owner-authorized Laravel routes. Do not use public/storage or public symlinks for private uploads.
5. Use byte uploads or a documented provider-supported upload flow for OCR. Mathpix cannot fetch localhost or a private local path; verify its current upload contract. No public AWS URL is required by this architecture.
6. Configure the database and queue worker; run migrations after the application implementation exists.
7. Serve via localhost or HTTPS for the service worker. Build and locally serve required JS/CSS/fonts; avoid runtime CDN dependencies in the offline shell.
8. Use provider fakes for automated tests and real credentials for the live demo. Never put keys in browser code, offline packs, prompts, committed files or recorded screens.

Missing credentials should produce a clear setup error. Do not silently fall back to seeded output. Label any mock or prerecorded demo explicitly.

## Existing ingestion environment
Read docs/EXTRACTION_STACK.md. Verify Composer packages and the Poppler `pdftotext`, `pdfinfo`, `pdfimages`, and `pdftoppm` binaries, plus ImageMagick `magick`. Upload validation allows PDFs up to 50 MB; ensure PHP `upload_max_filesize` and `post_max_size`, plus any reverse-proxy request limit, are at least that large. `MATERIAL_PAGE_BATCH_SIZE` sets the resumable PDF job size (default 5, maximum 10); `PDFIMAGES_PAGE_BATCH` bounds pages per individual image-inspection process (default 10). Keep the queue reservation timeout above the 120-second material-job timeout. Mathpix is the configured OCR provider for scans and STEM-signalled pages; do not expose its credentials. No new extraction dependency should be introduced before inspecting the existing services.
