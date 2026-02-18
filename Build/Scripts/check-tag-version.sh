#!/usr/bin/env bash
# Validates that ext_emconf.php version matches any semver tag pointing at HEAD.
# Used as a CaptainHook pre-push hook to prevent pushing mismatched versions.
set -euo pipefail

# Find semver tags (no v prefix) pointing at HEAD
TAGS=$(git tag --points-at HEAD | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' || true)

if [[ -z "${TAGS}" ]]; then
    # No semver tag at HEAD — nothing to validate
    exit 0
fi

# Extract version from ext_emconf.php
EMCONF_VERSION=$(grep -oP "'version'\s*=>\s*'\K[^']+" ext_emconf.php)

if [[ -z "${EMCONF_VERSION}" ]]; then
    echo "ERROR: Could not extract version from ext_emconf.php"
    exit 1
fi

# Check each tag against ext_emconf.php
while IFS= read -r TAG; do
    if [[ "${TAG}" != "${EMCONF_VERSION}" ]]; then
        echo "ERROR: Tag ${TAG} does not match ext_emconf.php version ${EMCONF_VERSION}"
        echo "Update ext_emconf.php version to '${TAG}' and amend your commit before pushing."
        exit 1
    fi
done <<< "${TAGS}"

echo "Version check passed: ext_emconf.php (${EMCONF_VERSION}) matches tag(s)"
