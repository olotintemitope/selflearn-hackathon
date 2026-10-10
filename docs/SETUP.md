# Local recording setup
1. Create or use a Laravel application; preserve its generated .env and APP_KEY.
2. Merge .env.hackathon.example settings into .env. Set your Mathpix app ID/key and chosen AI provider credentials locally. The OpenAI fields are the default proposal; adapt for another provider.
3. Wire proposed environment variables into Laravel config and read configuration from services. Verify current official provider documentation before implementing API requests. Do not assume these variables configure an integration automatically.
4. Use a private local disk for originals. Inspect its actual configured root; serve through owner-authorized Laravel routes. Do not use public/storage or public symlinks for private uploads.
5. Use byte uploads or a documented provider-supported upload flow for OCR. Mathpix cannot fetch localhost or a private local path; verify its current upload contract. No public AWS URL is required by this architecture.
6. Configure the database and queue worker; run migrations after the application implementation exists.
7. Serve via localhost or HTTPS for the service worker. Build and locally serve required JS/CSS/fonts; avoid runtime CDN dependencies in the offline shell.
8. Use provider fakes for automated tests and real credentials for the live demo. Never put keys in browser code, offline packs, prompts, committed files or recorded screens.

Missing credentials should produce a clear setup error. Do not silently fall back to seeded output. Label any mock or prerecorded demo explicitly.
