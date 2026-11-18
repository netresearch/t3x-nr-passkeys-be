# SPDX-License-Identifier: MIT
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Verify the local copies of release orchestration against their pinned upstream source."""

import re
import subprocess
from pathlib import Path


WORKFLOWS = Path(".github/workflows")
RAW = "https://raw.githubusercontent.com/netresearch"


def upstream(path):
    # Never execute fetched content. An unexpected redirect is a failed check.
    return subprocess.check_output(
        ["curl", "--fail", "--silent", "--show-error", "--proto", "=https",
         "--max-time", "30", "--retry", "2", f"{RAW}/{path}"]
    )


def check_release(caller, name):
    # Verifying a snapshot only protects the release if the entry point uses it.
    entry = (WORKFLOWS / caller).read_bytes()
    calls = re.findall(rb"^\s*(?:- )?uses:\s+([^#\s]+)", entry, flags=re.M)
    target = f"./{WORKFLOWS}/{name}-pinned.yml".encode()
    if calls != [target]:
        raise SystemExit(f"{caller}: the entry point must invoke the verified local workflow.")
    local = (WORKFLOWS / f"{name}-pinned.yml").read_bytes()
    header = re.match(
        rb"# SPDX-License-Identifier: MIT\n# SPDX-FileCopyrightText: Netresearch DTT GmbH\n"
        rb"# Upstream: netresearch/typo3-ci-workflows@([a-f0-9]{40})\n"
        rb"# The CI repository check verifies the upstream file and the three publishing pins\.\n",
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
    if expected != body:
        raise SystemExit(f"{name}: upstream orchestration differs beyond the three pinned references.")


check_release("release.yml", "release-typo3-extension")
check_release("republish.yml", "republish")
