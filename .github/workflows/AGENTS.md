<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md -- .github/workflows

## Overview
All CI is delegated to central reusable workflows in `netresearch/typo3-ci-workflows` and `netresearch/.github`. Local files are thin callers: `ci.yml` carries the extension-specific test matrix (intentional drift), `checks.yml` is the byte-identical, drift-enforced security/quality gate shared by every typo3-extension repo.

## Workflow files
| File | Purpose |
|------|---------|
| `ci.yml` | Test matrix (PHP 8.2-8.5 x TYPO3 ^12.4/^13.4/^14.3). Thin caller of `typo3-ci-workflows/ci.yml@main`: lint, cgl, phpstan (+ unpinned advisory), rector, unit, functional (MySQL), docs render, gate `ci / All CI checks` |
| `checks.yml` | Security/quality gate. Calls security (composer-audit + opengrep), gitleaks, zizmor, fuzz (fuzz + mutation testing), license-check, codeql, scorecard, dependency-review, pr-quality; gate `All security checks` |
| `harness-verify.yml` | Agent-harness consistency check via `Build/Scripts/verify-harness.sh` (thin caller of `netresearch/.github` script-check) |
| `release.yml` | Release orchestrator -- tag push triggers build + TER publish + Packagist verify + docs verify + atomic GitHub release. Thin caller of `typo3-ci-workflows/release-typo3-extension.yml@main` |
| `republish.yml` | `workflow_dispatch` manual re-run of TER / docs / Packagist verification for an existing tag. Never mutates the GitHub release. Thin caller of `typo3-ci-workflows/republish.yml@main` |
| `docs.yml` | Documentation render check (`typo3-ci-workflows/docs.yml@main`) |
| `check-template-drift.yml` | Enforces that `checks.yml` stays byte-identical to the org template |
| `ddev-hardening.yml` | Local job: DDEV ref-name sanitization check |
| `auto-merge-deps.yml` | Auto-merge dependency PRs (Dependabot/Renovate) |
| `community.yml`, `labeler.yml` | Stale/lock/greetings, PR labeler (org reusables) |
| `codeql.yml`, `scorecard.yml`, `dependency-review.yml`, `pr-quality.yml` | Standalone thin callers of the same org reusables that `checks.yml` also invokes |

## Common patterns
- Every job in a caller grants exactly the reusable's permission contract; `permissions: {}` at workflow level
- Any job added to `checks.yml` MUST also be added to its `gate.needs` list -- a job missing there can fail without blocking a merge (silent coverage loss)
- Branch rulesets require the stable gate names (`ci / All CI checks`, `All security checks`), never per-matrix job names: matrix/PR-only job contexts never materialize on a `merge_group` ref and would stall the merge queue until timeout
- E2E tests have NO workflow here at present; they run locally via `Build/Scripts/runTests.sh -s e2e`, which installs its own TYPO3 in containers. **NEVER use DDEV in CI.**
- Mutation testing runs in CI inside the `fuzz` reusable (thresholds MSI >= 80%, covered-MSI >= 80%, from `Build/infection.json5`)

## Conventions
- Action SHA-pinning, harden-runner, and tool setup are maintained centrally in the reusables; locally-defined steps (e.g. in `ddev-hardening.yml`, the `gate` job) pin to full SHA with version comment
- The typo3-ci-workflows reusable auto-detects tool commands from the `ci:*` composer scripts -- keep those scripts as the single source of truth
- `remove-dev-deps` in `ci.yml` handles dev-dependency incompatibilities per TYPO3 version

## Security
- Minimal permissions per call site; no reliance on default workflow permissions
- Never expose secrets in logs; `CODECOV_TOKEN` is the only secret ci.yml forwards
- Security scanning: composer-audit, opengrep, gitleaks, zizmor, CodeQL (`languages: auto` so shipped JS is analyzed), Scorecard

## Checklist (when modifying CI)
- [ ] Matrix or feature-flag changes go into `ci.yml` `with:` inputs, not new local jobs
- [ ] Never hand-edit `checks.yml` away from the org template (drift check will fail); change the template instead
- [ ] New `checks.yml` job also added to `gate.needs`
- [ ] Ruleset-required contexts remain the stable gate names

## Examples (golden samples)
| Pattern | Reference |
|---------|-----------|
| Intentional-drift thin caller | `ci.yml` |
| Byte-identical org template caller | `checks.yml` |
| Tag-triggered release orchestration | `release.yml` |

## Release gotchas
- Always bump `ext_emconf.php` version BEFORE creating the tag; the orchestrator rejects mismatches. `guides.xml` version should match too.
- Tag must be `v`-prefixed (e.g. `v0.8.0`); the extension version in `ext_emconf.php` is without the `v`.
- If the orchestrator aborts mid-run (e.g. TER times out), re-run the Release workflow against the same tag -- every step is idempotent. Republish is only for a completed release whose publishes later regressed.
- The GitHub release is created LAST, atomically, with all assets. Never edit a published release; cut a new version instead. Compatible with GitHub Immutable Releases.

## When stuck
- Reusable inputs/behavior: read the workflow source in `netresearch/typo3-ci-workflows` (`gh api` or local checkout) -- it is the authority, not this file
- Merge-queue stalls: check which contexts the ruleset requires vs which the event actually produces
- Root `AGENTS.md` for project-wide rules
