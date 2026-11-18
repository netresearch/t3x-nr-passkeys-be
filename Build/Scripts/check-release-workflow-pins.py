# SPDX-License-Identifier: MIT
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Verify the local copies of release orchestration against their pinned upstream source."""

import difflib
import hashlib
import re
import subprocess
from pathlib import Path


WORKFLOWS = Path(".github/workflows")
RAW = "https://raw.githubusercontent.com/netresearch"
# SHA-256 of the reviewed zero-context unified diff after the publishing pins
# are applied. The diff includes hunk locations, so it cannot authorize the
# same change at another call site.
DRAFT_CHANGE_SHA256 = "a60fdf24e19462b9e8a67292c4b15ecea22aead7bd93944ff9a1ba5a89f886b5"


def upstream(path):
    # Never execute fetched content. An unexpected redirect is a failed check.
    return subprocess.check_output(
        ["curl", "--fail", "--silent", "--show-error", "--proto", "=https",
         "--max-time", "30", "--retry", "2", f"{RAW}/{path}"]
    )


def release_notes(workflow, field):
    # Preserve the complete YAML literal, including its indentation and newlines.
    bodies = re.findall(rb"^          " + field + rb": \|\n((?:(?: {12}[^\n]*)?\n)+)", workflow, flags=re.M)
    if len(bodies) != 1:
        raise SystemExit("Expected exactly one release body.")
    return bodies[0]


def check_draft_creation(upstream_workflow, local_workflow):
    if release_notes(upstream_workflow, b"body") != release_notes(local_workflow, b"RELEASE_BODY"):
        raise SystemExit("The draft must use the complete upstream release notes.")

    changes = difflib.diff_bytes(
        difflib.unified_diff,
        upstream_workflow.splitlines(keepends=True),
        local_workflow.splitlines(keepends=True),
        n=0,
    )
    if hashlib.sha256(b"".join(changes)).hexdigest() != DRAFT_CHANGE_SHA256:
        raise SystemExit("The workflow differs from upstream beyond the reviewed native draft changes.")


def check_release(caller, name):
    # Verifying a snapshot only protects the release if the entry point uses it.
    entry = (WORKFLOWS / caller).read_bytes()
    calls = re.findall(rb"^\s*(?:- )?uses:\s+([^#\s]+)", entry, flags=re.M)
    target = f"./{WORKFLOWS}/{name}-pinned.yml".encode()
    if calls != [target]:
        raise SystemExit(f"{caller}: the entry point must invoke the verified local workflow.")
    local = (WORKFLOWS / f"{name}-pinned.yml").read_bytes()
    verification = (
        b"# CI verifies the upstream workflow, the three publishing pins and native draft creation.\n"
        if name == "release-typo3-extension"
        else b"# The CI repository check verifies the upstream file and the three publishing pins.\n"
    )
    header = re.match(
        rb"# SPDX-License-Identifier: MIT\n# SPDX-FileCopyrightText: Netresearch DTT GmbH\n"
        rb"# Upstream: netresearch/typo3-ci-workflows@([a-f0-9]{40})\n"
        + re.escape(verification),
        local,
    )
    if header is None:
        raise SystemExit(f"{name}: missing source revision.")
    sha = header[1]
    body = local[header.end():]
    expected = upstream(f"typo3-ci-workflows/{sha.decode()}/{WORKFLOWS}/{name}.yml")
    for target in (b"ter", b"packagist", b"docs"):
        prefix = b"    uses: netresearch/typo3-ci-workflows/.github/workflows/publish-to-" + target + b".yml@"
        expected, count = re.subn(b"^" + re.escape(prefix) + b"main$", prefix + sha, expected, flags=re.M)
        if count != 1:
            raise SystemExit(f"{name}: review the upstream {target.decode()} call.")
    for ref in re.findall(rb"^\s*(?:- )?uses:\s+([^#\s]+)", body, flags=re.M):
        if not re.fullmatch(rb"[^@]+@[a-f0-9]{40}", ref):
            raise SystemExit(f"{name}: every external action and workflow requires a commit SHA.")
    if name == "release-typo3-extension":
        check_draft_creation(expected, body)
    elif expected != body:
        raise SystemExit(f"{name}: upstream orchestration differs beyond the three pinned references.")


check_release("release.yml", "release-typo3-extension")
check_release("republish.yml", "republish")
