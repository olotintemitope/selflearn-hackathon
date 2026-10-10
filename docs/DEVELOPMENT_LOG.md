# Development evidence

| Slice | Codex/tools used | Verification | Human decision / limitation |
| --- | --- | --- | --- |
| Upload → extraction → visible concepts | Laravel 13, Mathpix byte-upload adapter, OpenAI Responses structured output, provider fakes, Playwright live browser | Pint passed; 11 focused tests / 35 assertions passed; fresh migrations passed; Vite production build passed; npm audit found 0 vulnerabilities; desktop and 390×844 browser flows verified for separate registration/login, upload, pending state, download, sign-out, guest redirect, and cross-user 403 | Real Mathpix/OpenAI completion was not exercised because credentials were not supplied; current local Node 21 is unsupported, so `.nvmrc` pins Node 22.12+ |
