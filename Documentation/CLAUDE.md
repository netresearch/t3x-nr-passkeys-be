<!-- FOR AI AGENTS - Scoped to Documentation/ -->
<!-- Last updated: 2026-03-02 -->

# Documentation AGENTS.md

**Scope:** TYPO3 extension documentation following docs.typo3.org standards.

## Structure

```
Documentation/
  Index.rst                          -> Main entry point (toctree)
  guides.xml                         -> Render configuration (version must match ext_emconf.php)
  Includes.rst.txt                   -> Shared substitutions and highlight directive
  Introduction/Index.rst             -> What the extension does, features, support matrix
  Installation/Index.rst             -> Composer install, activation, system requirements
  Configuration/Index.rst            -> Extension settings (13 confvals)
  DeploymentScenarios/Index.rst      -> Multi-env setup, DB sync, shared rpId
  DeploymentScenarios/Onboarding.rst -> User onboarding, recovery, DDEV, containers
  Usage/Index.rst                    -> End-user guide: registering/using passkeys
  Administration/Index.rst           -> Admin guide: API endpoints, lockouts, revocation
  Administration/Enforcement.rst     -> Enforcement levels, grace periods, dashboard
  Administration/Database.rst        -> Credential lifecycle, table schema, monitoring
  DeveloperGuide/Index.rst           -> Developer guide entry point
  DeveloperGuide/Architecture.rst    -> Extension structure, auth flow, domain model
  DeveloperGuide/ControllersAndServices.rst -> Routes, controllers, services, JS modules
  DeveloperGuide/Testing.rst         -> Test commands and test suite overview
  Security/Index.rst                 -> Security model, threat mitigation
  Security/Deployment.rst            -> Production deployment requirements
  Troubleshooting/Index.rst          -> Common errors, logging, debug mode
  Changelog/Index.rst                -> Version history
```

## Standards

- **Format**: reStructuredText (.rst)
- **Encoding**: UTF-8, LF line endings, 4-space indentation
- **Max line length**: 80 characters
- **File naming**: CamelCase directories, `Index.rst` in each
- **Headings**: Sentence case, underline characters: `=` (h1), `-` (h2), `~` (h3), `^` (h4)
- **Code blocks**: Use `.. code-block::` with `:caption:` for 5+ lines
- **Cross-references**: Use `:ref:` labels, not file paths
- **TYPO3 directives**: `.. confval::`, `.. versionadded::`, `.. deprecated::`, `.. note::`, `.. tip::`

## Rendering

```bash
# Local rendering via DDEV
ddev docs

# Or directly via Docker
docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest \
  --no-progress --output=/project/Documentation-GENERATED-temp /project/Documentation
```

Output goes to `Documentation-GENERATED-temp/` (gitignored).

## Publishing

- Published via docs.typo3.org webhook (configured on GitHub)
- Extension registered at extensions.typo3.org as `nr_passkeys_be`
- `guides.xml` contains interlink shortcode and project metadata

## Rules

- Update `guides.xml` version + `ext_emconf.php` version together at release time
- Keep RST compatible with TYPO3 render-guides (phpDocumentor-based)
- Screenshots go in `Images/` subdirectories as PNG with `:alt:` text
- Every directory must have an `Index.rst`
- Use `.. versionadded::` for new features, `.. deprecated::` for removed ones
- Keep README.md and Documentation/ in sync (config names, feature list, API endpoints)
- Use `.. confval::` for configuration settings, `:guilabel:` for UI elements
- Route paths in docs are relative to `/typo3/` (see DeveloperGuide note)
