<!-- FOR AI AGENTS - Scoped to Documentation/ -->
<!-- Last updated: 2026-02-09 -->

# Documentation AGENTS.md

**Scope:** TYPO3 extension documentation following docs.typo3.org standards.

## Structure

```
Documentation/
  Index.rst                 -> Main entry point (toctree)
  guides.xml                -> Render configuration (project metadata, interlinking)
  Introduction/Index.rst    -> What the extension does, features, support matrix
  Installation/Index.rst    -> Composer install, activation, system requirements
  Configuration/Index.rst   -> Extension settings, TypoScript (if any)
  Usage/Index.rst           -> End-user guide: registering/using passkeys
  Administration/Index.rst  -> Admin guide: managing users, lockouts, revocation
  DeveloperGuide/Index.rst  -> Architecture, API endpoints, extending
  Security/Index.rst        -> Security model, threat mitigation, audit results
  Changelog/Index.rst       -> Version history
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

- Do NOT edit `guides.xml` project version (managed by release process)
- Keep RST compatible with TYPO3 render-guides (phpDocumentor-based)
- Screenshots go in `Images/` subdirectories as PNG with `:alt:` text
- Every directory must have an `Index.rst`
