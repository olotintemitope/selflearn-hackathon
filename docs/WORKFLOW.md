# Codex workflow
1. Select a backlog slice and define concrete acceptance criteria.
2. Ask Codex to read AGENTS.md, relevant docs, and the existing application.
3. Implement one demonstrable slice on a focused branch.
4. Run relevant tests and exercise the visible learner flow.
5. Review the diff in a fresh context using verify-feature.
6. Prepare a PR with behavior, validation and limitations; human verifies and merges.

Use issue-to-pr for implementation and build-ai-feature for model-backed features.
Keep an evidence log in docs/DEVELOPMENT_LOG.md: task, tools used, checks, findings, human decisions. Do not record secrets or private learner content.

## MCP boundaries
GitHub tooling can supply issue/PR context if available. Official OpenAI developer documentation tooling supplies API reference context. Configure these in your Codex environment using current official documentation; this starter does not configure accounts or grant access.
Native shell/git tools cover local files. Mathpix and the model API are application integrations, not required development MCP servers.
Prefer least privilege and test infrastructure. No production deployment is included in the workflow.
