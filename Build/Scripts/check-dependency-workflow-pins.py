# SPDX-License-Identifier: MIT
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Compare the pinned dependency workflow with the organization template."""

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


def check_auto_merge():
    name = "auto-merge-deps.yml"
    local = (WORKFLOWS / name).read_bytes()
    prefix = b"uses: netresearch/.github/.github/workflows/auto-merge-deps.yml@"
    normalized, count = re.subn(
        re.escape(prefix) + rb"[a-f0-9]{40}(?=\r?$)", prefix + b"main", local, flags=re.M
    )
    expected = upstream(f".github/main/templates/typo3-extension/{WORKFLOWS}/{name}")
    if count != 1 or normalized != expected:
        raise SystemExit(f"{name}: compare the pinned caller with the organization template.")


check_auto_merge()
