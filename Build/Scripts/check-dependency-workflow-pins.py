# SPDX-License-Identifier: MIT
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Compare the pinned dependency workflow with the organization template."""

import json
import re
import subprocess
from pathlib import Path


WORKFLOWS = Path(".github/workflows")
RAW = "https://raw.githubusercontent.com/netresearch"


def upstream(path):
    # Never execute fetched content. An unexpected redirect is a failed check.
    return subprocess.check_output(
        ["curl", "--fail", "--silent", "--show-error", "--proto", "=https",
         "--location", "--max-redirs", "0", "--max-time", "30", "--retry", "2", f"{RAW}/{path}"]
    )


def has_workflow_call(workflow):
    # yq on the Ubuntu runner parses "on" as a YAML 1.2 string key.
    events = json.loads(subprocess.check_output(
        ["yq", "-o=json", "-I=0", ".on", "-"], input=workflow
    ))
    if isinstance(events, (dict, list)):
        return "workflow_call" in events
    return events == "workflow_call"


def check_auto_merge():
    name = "auto-merge-deps.yml"
    local = (WORKFLOWS / name).read_bytes()
    prefix = b"uses: netresearch/.github/.github/workflows/auto-merge-deps.yml@"
    pins = list(re.finditer(re.escape(prefix) + rb"([a-f0-9]{40})(?=\r?$)", local, re.M))
    if len(pins) != 1:
        raise SystemExit(f"{name}: expected one immutable workflow reference.")
    pin = pins[0]
    normalized = local[:pin.start(1)] + b"main" + local[pin.end(1):]
    expected = upstream(f".github/main/templates/typo3-extension/{WORKFLOWS}/{name}")
    if normalized != expected:
        raise SystemExit(f"{name}: compare the pinned caller with the organization template.")
    workflow = upstream(f".github/{pin[1].decode()}/{WORKFLOWS}/{name}")
    if not has_workflow_call(workflow):
        raise SystemExit(f"{name}: the pinned workflow must support workflow_call.")


check_auto_merge()
