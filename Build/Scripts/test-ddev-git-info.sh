#!/bin/sh
#
# Copyright (c) 2025-2026 Netresearch DTT GmbH
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Guards the sanitization that keeps a git ref name from reaching an interpreter
# in the DDEV tooling.
#
# Git accepts quotes, ";", "$", backticks, ">" and "|" in ref names. Two places
# splice a ref-derived value into an interpreter string: the post-start hook in
# .ddev/config.yaml (a shell inside the web container) and the sed expressions in
# .ddev/commands/web/generate-index. Checking out a fork PR would otherwise be
# enough to run commands in the container.
#
# POSIX sh, no dependencies beyond grep/tr/cut/printf and sed — sed only because
# one of the guarded call sites is itself a sed expression — so it runs anywhere
# the repository is checked out.
#
# Usage: sh Build/Scripts/test-ddev-git-info.sh

set -eu

CDPATH=''
ROOT=$(cd -- "$(dirname -- "$0")/../.." && pwd)
CONFIG="$ROOT/.ddev/config.yaml"
GENERATE_INDEX="$ROOT/.ddev/commands/web/generate-index"

# The character class the sources must use. Kept here as the single expected
# value so that changing a source without updating this test fails loudly
# instead of silently weakening the guard.
SANITIZER="tr -cd 'A-Za-z0-9._/-'"

failures=0

fail() {
    reason="$1"
    printf 'FAIL: %s\n' "$reason" >&2
    failures=$((failures + 1))
}

pass() {
    reason="$1"
    printf 'ok: %s\n' "$reason"
}

# Apply exactly the sanitization the sources apply.
sanitize() {
    raw_value="$1"
    printf '%s' "$raw_value" | tr -cd 'A-Za-z0-9._/-' | cut -c1-100
}

# --- 1. The sources still sanitize -------------------------------------------

for file in "$CONFIG" "$GENERATE_INDEX"; do
    if [ ! -f "$file" ]; then
        fail "missing file: $file"
        continue
    fi

    if grep -qF "$SANITIZER" "$file"; then
        pass "$(basename "$file") applies $SANITIZER"
    else
        fail "$(basename "$file") no longer applies $SANITIZER — the ref name reaches an interpreter unfiltered"
    fi
done

# A value stripped to nothing must not leave an empty interpolation behind.
if grep -q 'BRANCH="unknown"' "$CONFIG"; then
    pass "config.yaml keeps a fallback for a fully-stripped branch name"
else
    fail "config.yaml lost its fallback for a fully-stripped branch name"
fi

if grep -q 'GIT_BRANCH="main"' "$GENERATE_INDEX"; then
    pass "generate-index keeps a fallback for a fully-stripped branch name"
else
    fail "generate-index lost its fallback for a fully-stripped branch name"
fi

# --- 2. Hostile ref names lose every dangerous character ----------------------

# Each of these is a name `git check-ref-format --branch` accepts.
for hostile in \
    "fix/typo';id;'" \
    'feat/a|b' \
    'feat/a;id' \
    'feat/$(id)' \
    'feat/a>b' \
    'feat/a"b'
do
    got=$(sanitize "$hostile")

    # Anything outside the allowed class means the guard let something through.
    if printf '%s' "$got" | grep -q '[^A-Za-z0-9._/-]'; then
        fail "sanitizing [$hostile] left a dangerous character: [$got]"
    else
        pass "sanitized [$hostile] -> [$got]"
    fi
done

# --- 3. The shell command shape no longer executes anything -------------------

# Reproduces the command string .ddev/config.yaml hands to a shell in the
# container. With the raw name this prints the JSON and then runs `id`.
run_hook_shape() {
    branch="$1"
    sh -c "printf '{\"branch\":\"%s\",\"commit\":\"%s\",\"pr\":\"%s\"}' '$branch' 'abc1234' '42'" 2>&1
}

raw_out=$(run_hook_shape "fix/typo';id;'" || true)
if printf '%s' "$raw_out" | grep -q 'uid='; then
    pass "unsanitized value would execute a command (guard is load-bearing)"
else
    fail "the injection no longer reproduces — this test can no longer prove the guard works"
fi

safe_out=$(run_hook_shape "$(sanitize "fix/typo';id;'")" || true)
if printf '%s' "$safe_out" | grep -q 'uid='; then
    fail "sanitized value still executed a command: [$safe_out]"
else
    pass "sanitized value produces data only: [$safe_out]"
fi

# --- 4. The sed expression shape stays intact --------------------------------

sed_input=$(printf 'branch: {{GIT_BRANCH}}\n')

if printf '%s\n' "$sed_input" | sed -e "s|{{GIT_BRANCH}}|feat/a|b|g" >/dev/null 2>&1; then
    fail "a raw '|' no longer breaks the sed expression — this test can no longer prove the guard works"
else
    pass "unsanitized '|' breaks the sed expression (guard is load-bearing)"
fi

safe_branch=$(sanitize 'feat/a|b')
if printf '%s\n' "$sed_input" | sed -e "s|{{GIT_BRANCH}}|$safe_branch|g" >/dev/null 2>&1; then
    pass "sanitized value keeps the sed expression valid"
else
    fail "sanitized value still breaks the sed expression"
fi

# --- 5. Ordinary ref names are untouched -------------------------------------

for ordinary in \
    main \
    fix/security-scan-findings \
    release/1.2.0 \
    feature/TICKET-123-desc \
    chore/typo3-14.3 \
    dependabot/composer/vendor/package-1.2.3
do
    got=$(sanitize "$ordinary")
    if [ "$got" = "$ordinary" ]; then
        pass "unchanged: $ordinary"
    else
        fail "ordinary name altered: [$ordinary] -> [$got]"
    fi
done

# --- 6. Overlong names are capped --------------------------------------------

# Built with a plain loop rather than `seq`, which is not POSIX.
long=''
i=0
while [ "$i" -lt 250 ]; do
    long="${long}a"
    i=$((i + 1))
done

got=$(sanitize "$long")
if [ "${#got}" -eq 100 ]; then
    pass "overlong name capped at 100 characters"
else
    fail "overlong name not capped: got ${#got} characters"
fi

# --- summary -----------------------------------------------------------------

if [ "$failures" -eq 0 ]; then
    printf '\nAll DDEV hook hardening checks passed.\n'
    exit 0
fi

printf '\n%d check(s) failed.\n' "$failures" >&2
exit 1
