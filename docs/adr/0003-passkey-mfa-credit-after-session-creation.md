<!-- Copyright (c) 2025-2026 Netresearch DTT GmbH -->
<!-- SPDX-License-Identifier: GPL-2.0-or-later -->

# ADR-0003: Passkey MFA Credit After Session Creation

- **Status:** Accepted
- **Date:** 2026-11-24
- **Supersedes:** n/a

## Context

With `skipMfaOnPasskeyAuth` enabled, a verified passkey login is meant to
skip the TYPO3 MFA challenge. Until this decision the authentication service
wrote the session key `mfa` while `authUser()` ran.

TYPO3 (`AbstractUserAuthentication::checkAuthentication()`) runs the
authentication services first, creates the session of the new login
afterwards and calls `evaluateMfaRequirements()` right after that, with no
event in between. Session data written during authentication survives only
if the browser already had an anonymous session. A fresh browser session,
which is the normal case, starts empty, so the `mfa` key was lost and the
user got the MFA challenge after all. On v1.0.3 with the Redis session
backend the early write additionally failed with
`SessionNotUpdatedException` (#1484389971), an HTTP 500.

PR #195 documented the behaviour and left two questions to a maintainer:

1. How to bring the passkey result into the session the Core creates.
2. Whether a passkey may replace the MFA setup that `BE.requireMfa`
   demands, or only an MFA provider the user already has.

## Decision

### Policy

A verified passkey stands in for an MFA provider the user has set up. It
never replaces setting one up. MFA is credited only if all of these hold:

- the assertion was user-verified (PIN or biometric) and
  `skipMfaOnPasskeyAuth` is enabled;
- the user has at least one active MFA provider;
- no MFA provider of the user is locked;
- the request is not an AJAX route.

With `BE.requireMfa`, users without a provider are therefore still sent to
the MFA setup once, also after a passkey login. Password logins follow the
TYPO3 MFA configuration unchanged.

### Mechanism

- `PasskeyAuthenticationService` no longer writes `mfa`. It writes the
  passkey marker and records the verified login in
  `PasskeyLoginProofStore`, a shared service holding a `WeakMap` keyed by
  the user authentication object. The proof lives only as long as that
  object, i.e. one request.
- The backend middleware `netresearch/nr-passkeys-be/passkey-login-completion`
  (`PasskeyLoginCompletion`) wraps `typo3/cms-backend/authentication`. After
  the inner stack has run, it takes the proof (also when the inner stack
  throws), checks that the session belongs to the user of the proof, writes
  the marker and, under the policy above, `mfa` into the session the Core
  created.
- If MFA was credited and the Core already answered with a redirect to
  `auth_mfa` or `setup_mfa`, only the `Location` header is replaced by the
  login target the Core `MfaController` uses after a successful
  verification. Status and `Set-Cookie` stay unchanged.

### Rationale

1. **Every account keeps a second factor of its own.** If a passkey could
   replace the MFA setup, an account with a passkey and a password would
   have no second factor on its password login. Enabling `requireMfa` is a
   decision for all users, so it applies to passkey users as well.
2. **A locked provider stays locked.** A provider locked after failed
   attempts signals an attack on the account. The passkey login must not
   unlock it silently; an administrator has to.
3. **AJAX routes cannot carry the result.** The Core answers MFA on AJAX
   routes, such as the re-login dialog (`/ajax/login`), with a bare 401;
   the session cookie never reaches the browser. Crediting MFA there would
   change nothing for the user and only widen the code path.
4. **No early session writes.** The extension writes to the session only
   after the Core created it, which removes the Redis 500 and works the same
   with every session backend.

## Consequences

### Positive

- Passkey logins skip the MFA challenge on fresh browser sessions, which is
  what `skipMfaOnPasskeyAuth` promises.
- No extra anonymous session, no fake MFA provider, no Core change.
- Functional tests run the login through the real `BackendUserAuthenticator`
  and pin the middleware order, so a Core change in this area shows up as a
  failing test.

### Negative

- The middleware relies on Core behaviour that is not a public API:
  `$GLOBALS['BE_USER']` after the inner stack, the session-creation
  semantics of `checkAuthentication()` and the paths of the MFA redirects.
- Behaviour operators will notice after the upgrade:
  - With `requireMfa`, passkey users without an MFA provider are sent to the
    MFA setup once. Before, the old service code could credit MFA without a
    provider on the rare existing-anonymous-session path.
  - A locked MFA provider also blocks the passkey login until an
    administrator unlocks it.

### Alternatives Considered

- **Anonymous backend session on the login page or the login-options
  endpoint.** The Core keeps the data of an existing anonymous session, so
  `mfa` would survive. Rejected because every visitor of the login page
  would get a backend session (session storage and cookie for anonymous
  traffic), and the result would still depend on that session existing
  when the login POST arrives, e.g. not after it expired or was cleared.
- **Own MFA provider "Passkey".** The Core would treat it as fulfilled via
  `getFirstAuthenticationAwareProvider()`. Rejected because it would appear
  in the MFA setup and user settings as a provider users can pick, and a
  registered passkey would then satisfy `requireMfa` on its own. Passkey
  users would never set up a second factor for their password login, which
  contradicts the policy above.
- **Upstream Core event between session creation and MFA evaluation.**
  The cleanest option: the middleware would become a plain event listener.
  Not available in TYPO3 12.4 to 14.3, which this extension supports, so it
  cannot fix the bug now. Worth proposing upstream; this ADR should be
  revisited once such an event exists.
