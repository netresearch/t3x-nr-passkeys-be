<!-- Copyright (c) 2025-2026 Netresearch DTT GmbH -->
<!-- SPDX-License-Identifier: GPL-2.0-or-later -->

# Contributing to nr_passkeys_be

Thank you for considering contributing to the TYPO3 Passkeys Backend Authentication extension.

This project follows our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to abide by its terms.

## Getting Started

1. Fork the repository
2. Clone your fork and create a feature branch
3. Install dependencies: `composer install`
4. Make your changes
5. Run quality checks (see below)
6. Submit a pull request

## Development Setup

```bash
composer install

# Verify everything works
composer ci:test:php:cgl          # Code style (PER-CS3.0)
composer ci:test:php:phpstan              # PHPStan level 10
composer ci:test:php:unit     # Unit tests
```

## Quality Requirements

All contributions must pass the following quality gates:

| Check | Command | Requirement |
|-------|---------|-------------|
| Code style | `composer ci:test:php:cgl` | PER-CS3.0 compliance |
| Static analysis | `composer ci:test:php:phpstan` | PHPStan level 10; CI runs it against TYPO3 12.4, 13.4 and 14.3, a local run covers only the TYPO3 version installed |
| Rector | `composer ci:test:php:rector` | No changes proposed |
| Canonical formatting | see [Code Style](#code-style) | The tree is unchanged after the canonical printer and `composer ci:cgl` |
| Unit tests | `composer ci:test:php:unit` | All tests pass |
| Mutation tests | `composer ci:mutation` | MSI >= 80%, covered MSI >= 80% (run locally; no CI workflow runs it) |

### Writing Tests

- New features must include unit tests
- Bug fixes should include a regression test
- Functional tests need a database: `Build/Scripts/runTests.sh -s functional -d mysql`
  runs them locally in containers; CI runs them on MySQL in `.github/workflows/ci.yml`
- Use `declare(strict_types=1)` in all PHP files

### Code Style

This project uses PHP-CS-Fixer with PER-CS3.0 coding standard. Fix style issues automatically:

```bash
composer ci:cgl
```

The `Canonical formatting` CI job also runs the canonical printer from
[`netresearch/php-ast-edit-skill`](https://github.com/netresearch/php-ast-edit-skill)
before PHP-CS-Fixer and fails when the tree changes. To run the same check
locally, check out the printer at the `PRINTER_REF` that
`.github/workflows/canonical-formatting.yml` sets, outside this repository.
Commit or stash your changes first: the commands rewrite files in place, and
the final `git diff` then shows only what the printer and PHP-CS-Fixer changed.

```bash
REF=$(sed -n 's/^ *PRINTER_REF: *//p' .github/workflows/canonical-formatting.yml) &&
PRINTER=$(mktemp -d) &&
git clone --depth 1 --branch "$REF" https://github.com/netresearch/php-ast-edit-skill.git "$PRINTER" &&
composer install --no-dev --working-dir="$PRINTER" &&
php "$PRINTER/bin/php-ast-edit" format --path . &&
composer ci:cgl &&
git diff --exit-code
```

## Pull Request Process

1. Ensure all CI checks pass
2. Update documentation if applicable
3. Add a changelog fragment for a change a user or integrator can notice (see [`changelog.d/README.md`](changelog.d/README.md))
4. Keep commits atomic -- one logical change per commit
5. Use conventional commit format: `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`
6. Sign your commits with GPG/SSH (`git commit -S`)

## Reporting Issues

- **Bugs**: Use the [bug report template](https://github.com/netresearch/t3x-nr-passkeys-be/issues/new?template=bug_report.md)
- **Features**: Use the [feature request template](https://github.com/netresearch/t3x-nr-passkeys-be/issues/new?template=feature_request.md)
- **Security**: See [SECURITY.md](SECURITY.md) for responsible disclosure

## Governance and policies

This extension follows the organisation-wide Netresearch policies:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md):
  ownership, roles, how decisions are made and how conflicts are resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md):
  planned and excluded work for the next twelve months.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings):
  which vulnerability, licence and static-analysis findings must be fixed,
  by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management):
  where CI and release credentials are stored, who may use them, how
  committed secrets are detected, and when secrets are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md):
  the people and teams with administrative or write access to this
  repository.

[`.github/CODEOWNERS`](.github/CODEOWNERS) assigns every path of this
repository to the `@netresearch/typo3` team.

Checks that run on every pull request in this repository:

- `.github/workflows/checks.yml`: Composer Audit (fails on a security
  advisory for an installed Composer package) and Opengrep SAST, both
  through `typo3-ci-workflows`' `security.yml` (`.semgrepignore` names the
  paths this repository leaves out of the scan; which Opengrep findings
  block a pull request is set by the organisation's
  [static analysis rule](https://github.com/netresearch/.github/blob/main/SECURITY.md#static-analysis-sast));
  Dependency Review (fails on a newly added dependency with a vulnerability
  of severity high or higher); the PHP licence check (`license-check.yml`,
  fails when the `composer licenses` output matches its forbidden-licence
  pattern, which by default refuses `SSPL`, `BSL` and `BUSL` and every
  `SSPL-` or `BUSL-` identifier of any version (`BSL-1.0` passes)); CodeQL
  for the JavaScript and the workflow files (CodeQL has no PHP analysis;
  PHPStan and Opengrep cover the PHP code); Betterleaks secret scanning;
  zizmor for the workflow
  files; the fuzz test suite; the pull request quality gate
  (`pr-quality.yml`: a size check on non-draft pull requests and the
  solo-maintainer auto-approval for non-draft pull requests that an owner,
  member or collaborator opens from a branch of this repository);
  and the aggregate `All security checks` job, which fails unless every
  one of these jobs succeeded or was skipped.
- `.github/workflows/ci.yml`: PHP lint, code style, PHPStan level 10,
  Rector, unit tests and functional tests on MySQL for PHP 8.2 to 8.6
  against TYPO3 12.4, 13.4 and 14.3 (PHP 8.6 not with 12.4), the changelog
  fragment check, and the documentation rendering; and
  the aggregate `All CI checks` job, which fails unless every CI job
  succeeded or was skipped.
- `.github/workflows/check-template-drift.yml`: Template drift compares
  the `.github/` files managed by the organisation's typo3-extension
  template with that template.
- `.github/workflows/canonical-formatting.yml`, `harness-verify.yml`,
  `js-tests.yml` (Vitest) and `e2e.yml` (Playwright against TYPO3 13.4 and
  14.3, skipped when a pull request changes only documentation);
  `docs.yml` and `ddev-hardening.yml` run when their paths change.

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.
