<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — .ddev

<!-- AGENTS-GENERATED:START overview -->
## Overview
DDEV local development environment configuration. **Use the `typo3-ddev` skill** for setup and multi-version testing.
<!-- AGENTS-GENERATED:END overview -->

## Setup
- `ddev start` (or `make up` from repo root for full multi-version install)
- direnv users: `direnv allow` -- `.envrc` exports TYPO3 paths and auto-installs CaptainHook git hooks from `.Build/bin/captainhook`
- DDEV is for local development ONLY -- never for running tests or CI

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `config.yaml` | Main DDEV configuration |
| `docker-compose.web.yaml`, `docker-compose.git-info.yaml` | Custom service overrides |
| `commands/host/` | Host-side commands: `docs`, `setup` |
| `commands/web/` | Container-side commands: `install-v12/v13/v14`, `install-all`, `generate-index`, `generate-makefile` |
| `apache/`, `web-build/`, `web-entrypoint.d/` | Webserver config, image build context, entrypoint hooks |
| `Makefile.template`, `index.html.netresearch.template` | Sources for generated Makefile / landing page |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START commands -->
## Commands
| Task | Command |
|------|---------|
| Start | `ddev start` |
| Stop | `ddev stop` |
| SSH into container | `ddev ssh` |
| Run composer | `ddev composer ...` |
| Database export | `ddev export-db > dump.sql.gz` |
| Database import | `ddev import-db < dump.sql.gz` |
| View logs | `ddev logs` |
| Restart | `ddev restart` |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns
- Use `ddev composer` instead of local composer
- Custom commands in `.ddev/commands/` for project-specific tasks
- Override services with `docker-compose.*.yaml` files
- Use `ddev describe` to see URLs and credentials
- Multi-version testing: change `php_version` in config.yaml
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style
- Keep `config.yaml` minimal, use overrides for complexity
- Document custom commands with `## Description:` header
- Use `#ddev-generated` comment for files DDEV manages
- Pin addon versions for reproducibility
<!-- AGENTS-GENERATED:END code-style -->

## Security
- Dev-only credentials (e.g. the local admin password) stay in DDEV/local templates -- never reuse them outside DDEV, never commit real secrets
- Config here is local-only; production deployment guidance lives in `Documentation/Security/Deployment.rst`

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] `ddev start` works after changes
- [ ] Custom commands have descriptions
- [ ] No hardcoded paths or credentials
- [ ] Works on macOS, Linux, and Windows (WSL2)
<!-- AGENTS-GENERATED:END checklist -->

## Examples (golden samples)
| Pattern | Reference |
|---------|-----------|
| Container-side custom command | `commands/web/install-v13` |
| Host-side custom command | `commands/host/docs` |
| Service override | `docker-compose.web.yaml` |

<!-- AGENTS-GENERATED:START skill-reference -->
## When stuck
> For DDEV setup, TYPO3 multi-version testing, and custom commands:
> **Invoke skill:** `typo3-ddev`
- URLs and credentials: `ddev describe`; root `AGENTS.md` for project-wide rules
<!-- AGENTS-GENERATED:END skill-reference -->
