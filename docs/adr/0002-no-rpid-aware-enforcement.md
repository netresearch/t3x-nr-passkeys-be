# ADR-0002: No rpId-Aware Password Enforcement

- **Status:** Accepted
- **Date:** 2026-02-25
- **Supersedes:** n/a

## Context

After implementing per-user password enforcement (ADR-0001), a follow-up
question arose: should the enforcement check whether the user's registered
passkeys are valid for the **current request domain** (rpId)?

Scenario: production database is synced to a staging or local environment.
The credential table contains passkeys bound to the production domain.
`disablePasswordLogin` is enabled. The user has passkeys in the database,
but none are usable on the current domain -- enforcement blocks password
login, effectively locking the user out on the non-production environment.

## Decision

**Do not add rpId-aware filtering to the enforcement check.**

The credential count query intentionally ignores which domain the passkeys
were registered on. The current behaviour is:

- `hasRegisteredPasskeys()` counts all non-deleted, non-revoked credentials
  for the user, regardless of rpId.
- If the count is > 0 and `disablePasswordLogin` is enabled, password login
  is blocked.

### Rationale

1. **Host header injection bypass vector.** The rpId is derived from the
   HTTP `Host` header (or `HTTP_X_FORWARDED_HOST`). If enforcement filtered
   by rpId, an attacker could send a crafted Host header for a domain with
   no registered passkeys, causing the count to be zero and bypassing
   enforcement entirely. Even with `trustedHostsPattern` configured, this
   adds an unnecessary attack surface that is difficult to audit.

2. **Conservative default is more secure.** Blocking password login when
   *any* passkey exists -- regardless of domain -- is the safer default.
   Users are never silently downgraded to password login due to domain
   mismatch.

3. **The scenario is operational, not architectural.** The problematic
   scenario (production DB on dev with enforcement enabled) is a deployment
   configuration issue. The correct fixes are operational:

   - Exclude `tx_nrpasskeysbe_credential` from database syncs (documented
     in Deployment Scenarios).
   - Use `TYPO3_CONTEXT`-based configuration to disable
     `disablePasswordLogin` on non-production environments (documented in
     Deployment Scenarios).
   - If credentials were accidentally synced, truncate the table on the
     non-production environment.

4. **Minimal code surface.** Not adding rpId filtering keeps the
   enforcement query simple and avoids coupling the auth service to
   request parsing and rpId resolution logic.

## Consequences

### Positive

- No new attack surface from Host header manipulation.
- Enforcement logic remains a single, auditable COUNT query.
- Deployment documentation covers the operational mitigations.

### Negative

- Users who sync production databases without excluding the credential
  table may experience unexpected password lockout on non-production
  environments. This is mitigated by documentation and by the
  `TYPO3_CONTEXT`-based configuration pattern.

### Alternatives Considered

- **rpId-aware count with allowlist:** Only count credentials matching a
  configured set of rpIds. Rejected because it requires additional
  configuration and still trusts the rpId derivation path.
- **rpId-aware count with strict trustedHostsPattern:** Rely on TYPO3's
  `trustedHostsPattern` to prevent Host header injection. Rejected because
  misconfigured patterns (e.g. `.*`) are common in development, and the
  enforcement bypass would be silent.
