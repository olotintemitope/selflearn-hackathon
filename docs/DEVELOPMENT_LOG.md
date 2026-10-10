# Development evidence

| Slice | Codex/tools used | Verification | Human decision / limitation |
| --- | --- | --- | --- |
| Upload → extraction → visible concepts | Laravel 13, Mathpix byte-upload adapter, OpenAI Responses structured output, provider fakes | Pint passed; 10 focused tests / 27 assertions passed; fresh migrations passed; 10 routes inspected; Vite production build passed; npm audit found 0 vulnerabilities | Real Mathpix/OpenAI calls not exercised because credentials were not supplied; current local Node 21 is unsupported, so `.nvmrc` pins Node 22.12+ |
