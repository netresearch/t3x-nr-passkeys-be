# ADR-0001: Per-User Password Login Enforcement

- **Status:** Accepted
- **Date:** 2026-02-25
- **PR:** [#25](https://github.com/netresearch/t3x-nr-passkeys-be/pull/25)

## Context

The `disablePasswordLogin` setting was designed to block password login
entirely when passkeys are enforced. However, the original implementation
in `getUser()` was a no-op -- both code paths returned `false` regardless
of the setting, and the SaltedPasswordService would still authenticate
password logins.

Even if fixed as a global kill-switch, the setting creates a chicken-and-egg
problem: new users cannot register passkeys because they cannot log in (no
password login available, no passkey registered yet).

## Decision

Enforce password login restrictions **per user** in `authUser()`:

- When `disablePasswordLogin` is enabled and the user has at least one
  active (non-deleted, non-revoked) passkey, return `0` (block).
- When the user has no passkeys, return `100` (pass to next service),
  allowing password login.

The enforcement happens in `authUser()` rather than `getUser()` because:

1. `authUser()` receives the resolved user record with the UID.
2. `authUser()` is called on all auth services in the chain, not just the
   one that found the user.
3. Priority 80 ensures this service runs before SaltedPasswordService (50),
   and `return 0` breaks the chain.

## Consequences

### Positive

- Gradual onboarding: new users log in with password, register passkey,
  then are automatically enforced.
- No lockouts: users without passkeys always have password access.
- Admin recovery: revoking all passkeys re-enables password login.

### Negative

- Query duplication: `hasRegisteredPasskeys()` in the auth service
  duplicates `CredentialRepository::countByBeUser()` because the auth
  service cannot use DI (extends `AbstractAuthenticationService`).
- No rpId awareness: the credential count ignores which domain the passkeys
  were registered on. This is intentional -- rpId-aware filtering would
  create a Host header injection bypass vector.

### Risks

- TOCTOU race: concurrent passkey removal requests could theoretically
  bypass the last-passkey guard in ManagementController. However, the
  auth-time enforcement self-heals: zero passkeys means password login
  is allowed, so the user is never permanently locked out.
