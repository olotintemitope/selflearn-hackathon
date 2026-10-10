# SelfLearn hackathon starter
Working name: SelfLearn. Domain-agnostic learning from learner-provided materials.

This is a planning and workflow starter, not a runnable Laravel application.
No dependencies, credentials, or MCP connections are installed.

## Start
1. Create a Laravel application in a separate directory with your preferred supported version.
2. Copy this starter into that application, including .agents and .github. Preserve the application's files.
3. Read AGENTS.md and docs/PRODUCT.md, then docs/WORKFLOW.md.
4. Follow docs/SETUP.md and merge .env.hackathon.example into your application’s local .env; never commit real credentials.
5. Start with docs/backlog/01-material-to-concepts.md.

Repository skills are under .agents/skills. They are project workflow files, not installed personal ChatGPT skills.
Use conventional commits and one focused branch per slice.

Suggested first prompt:
“Read AGENTS.md and docs/backlog/01-material-to-concepts.md. Inspect the application, implement the smallest complete vertical slice, run relevant checks, and report evidence for each acceptance criterion.”

## Recording POC decisions
Use private local storage; AWS credentials and infrastructure are not required. Mathpix and AI calls run server-side and require internet. See docs/LOW_BANDWIDTH.md for implementation and recorded proof. This package supplies requirements and placeholders, not working offline code.
