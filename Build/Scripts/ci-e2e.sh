#!/usr/bin/env bash
#
# CI wrapper for `runTests.sh -s e2e`.
#
# The reusable workflow netresearch/typo3-ci-workflows/.github/workflows/e2e.yml
# calls this with `setup-script: Build/Scripts/ci-e2e.sh` and no arguments. In
# that mode the script owns the whole pipeline, which here means handing over to
# the shared runner: it installs the composer dependencies, builds a TYPO3
# instance from the e2e_provision_* hooks in Build/Scripts/runTests.conf, brings
# up MariaDB, PHP-FPM and Apache, and drives Playwright against them.
#
# Environment the workflow provides: E2E_TYPO3_VERSION, E2E_VARIANT,
# E2E_TYPO3_PACKAGES and COMPOSER_RETRY. The first two are read by the shared
# provisioner, COMPOSER_RETRY is passed into the provisioning container by it,
# so this script only has to keep them exported.
#
# Local invocation calls the runner directly and needs no wrapper:
#   E2E_TYPO3_VERSION=14 ./Build/Scripts/runTests.sh -s e2e
#

set -euo pipefail

if [[ -z "${E2E_TYPO3_VERSION:-}" ]]; then
    echo "::error::ci-e2e.sh: E2E_TYPO3_VERSION is not set." >&2
    echo "  This script is the CI entry point. For a local run call" >&2
    echo "  ./Build/Scripts/runTests.sh -s e2e directly." >&2
    exit 1
fi

# A constraint such as `^14.3` is normalised to its major by the shared
# provisioner (e2e-provision.sh), so it is exported unchanged. TYPO3 12 has no
# e2e instance here: the provisioner rejects 11 and 12 and falls back to 13,
# which would silently test something other than the matrix cell says.
case "${E2E_TYPO3_VERSION}" in
    "^13"*|"13"*|"^14"*|"14"*) ;;
    *)
        echo "::error::ci-e2e.sh: unsupported E2E_TYPO3_VERSION '${E2E_TYPO3_VERSION}'." >&2
        echo "  The e2e instance exists for TYPO3 13 and 14. Fix the matrix in" >&2
        echo "  .github/workflows/e2e.yml or extend this wrapper." >&2
        exit 1
        ;;
esac

# PHP 8.5 is the e2e runtime. Pinned here so a matrix cell cannot resolve to
# another version: the suite asserts backend markup, not PHP behaviour, and the
# PHP matrix is covered by the unit and functional jobs in ci.yml.
PHP_VERSION=8.5

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "ci-e2e.sh: TYPO3 ${E2E_TYPO3_VERSION}, PHP ${PHP_VERSION}, variant=${E2E_VARIANT:-<none>}"

export E2E_TYPO3_VERSION
exec "${SCRIPT_DIR}/runTests.sh" -s e2e -p "${PHP_VERSION}"
