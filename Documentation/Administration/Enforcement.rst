..  include:: ../Includes.rst.txt

..  _enforcement:

======================
Passkey Enforcement
======================

..  versionadded:: 0.6.0

The extension supports per-group enforcement of passkey registration with
configurable grace periods. This allows administrators to gradually roll out
passkeys across their organisation -- from gentle encouragement to mandatory
adoption.

Enforcement levels
==================

Each backend user group can be assigned one of four enforcement levels. The
level controls how aggressively the extension prompts users to register a
passkey.

..  list-table:: Enforcement levels
    :header-rows: 1
    :widths: 15 15 70

    *   - Level
        - Severity
        - Behaviour

    *   - **Off**
        - 0
        - No prompts. Passkeys are fully optional. This is the default for all
          groups.

    *   - **Encourage**
        - 1
        - A dismissible banner is shown to users who have not registered a
          passkey. The banner explains what passkeys are, why to set them up,
          links to the extension documentation, and provides administrator
          contact guidance. Users can dismiss the banner and continue working.

    *   - **Required**
        - 2
        - An interstitial page appears after login for users without passkeys.
          During the grace period, users can click :guilabel:`Skip for now` to
          dismiss the interstitial for the remainder of their session. After the
          grace period expires, the interstitial becomes mandatory and cannot be
          skipped.

    *   - **Enforced**
        - 3
        - The strictest level. Password login is disabled for users who already
          have passkeys. Users who have not yet registered a passkey see a
          mandatory interstitial after login with no skip option.

..  tip::

    Start with **Encourage** for all groups. Once adoption reaches a
    comfortable level, move to **Required** with a generous grace period (e.g.
    30 days). Only set **Enforced** after all users in the group have had time
    to register their passkeys.


Configuring enforcement per group
=================================

Enforcement is configured on each backend user group record:

1. Go to :guilabel:`System > Backend Users` and select the
   :guilabel:`Backend User Groups` list.
2. Edit a group record.
3. Switch to the :guilabel:`Passkeys` tab.
4. Set the :guilabel:`Passkey Enforcement` dropdown to the desired level.
5. If the level is **Required**, configure the :guilabel:`Grace Period (Days)`
   field (default: 14 days, range: 1--365).

..  note::

    The :guilabel:`Grace Period (Days)` field is only visible when the
    enforcement level is set to **Required**. The **Enforced** level has no
    grace period -- the interstitial is always mandatory.


Grace period mechanics
======================

The grace period gives users time to register a passkey before the requirement
becomes mandatory:

1. An administrator sets a group to **Required** with a grace period (e.g. 14
   days).
2. The first time a user in that group logs in and the interstitial middleware
   intercepts them, the grace period starts (a timestamp is recorded on the
   ``be_users`` record).
3. During the grace period, the interstitial shows a countdown:
   *"You have N days remaining to set up your passkey."*
   The user can click :guilabel:`Skip for now` to dismiss the interstitial for
   the current session.
4. When the grace period expires, the interstitial becomes mandatory. The skip
   button is no longer available and the user must register a passkey to
   continue.

..  important::

    The grace period starts on the user's **first login** after their group
    moves to **Required**, not when the administrator changes the setting. Users
    who do not log in during the grace window will see the full grace period
    when they eventually log in.

The grace period start timestamp is stored in the ``passkey_grace_period_start``
column on the ``be_users`` table.


Multi-group resolution
======================

When a backend user belongs to multiple groups with different enforcement
settings, the extension resolves the effective level using two rules:

1. **Strictest level wins.** If a user belongs to group A (Encourage) and
   group B (Required), the effective level is **Required**.
2. **Shortest grace period wins among same-level groups.** If two groups are
   both set to Required but with different grace periods (group A: 30 days,
   group B: 14 days), the effective grace period is **14 days**.

..  code-block:: text

    User belongs to:
      - Editors (Encourage, 0 days)      -> severity 1
      - Content Managers (Required, 30d) -> severity 2
      - Reviewers (Required, 14d)        -> severity 2

    Effective: Required, 14-day grace period

Users with no group assignments default to enforcement level **Off**.

..  note::

    Enforcement considers only the groups **directly assigned** to a user
    (the ``be_users.usergroup`` field). TYPO3 subgroups (configured via
    ``be_groups.subgroup``) are **not** resolved for enforcement evaluation.
    If you configure enforcement on a subgroup, assign that group directly to
    the affected users or configure enforcement on the parent group instead.


Admin dashboard
===============

The :guilabel:`Admin Tools > Passkey Management` module provides a dashboard
for monitoring and managing passkey adoption across the organisation.

Dashboard tab
-------------

The dashboard tab shows:

- **Overall adoption statistics** -- A progress bar with the total number of
  backend users, how many have passkeys, and the adoption percentage.
- **Per-group enforcement table** -- Each group is listed with its current
  enforcement level, grace period, member count, passkey adoption count, and
  adoption percentage. Administrators can change a group's enforcement level
  directly from the dropdown in the table.
- **Users without passkeys** -- A list of users who have not yet registered a
  passkey, showing their username, real name, grace period start date, and
  remaining days. :guilabel:`Send reminder`, :guilabel:`Clear nudge`, and
  :guilabel:`Unlock` actions are available per user.

Help tab
--------

The help tab provides:

- **Rollout guide** -- Step-by-step instructions for rolling out passkeys
  across the organisation.
- **Recovery procedures** -- What to do when a user loses their authenticator
  device.
- **MFA coexistence** -- How passkey enforcement interacts with TYPO3's
  built-in MFA.
- **FAQ** -- Answers to common questions about passkey enforcement.


Monitoring adoption progress
============================

Use the dashboard's adoption statistics to track rollout progress:

1. Navigate to :guilabel:`Admin Tools > Passkey Management`.
2. The progress bar at the top shows overall adoption (e.g. "12 of 25 users
   have passkeys -- 48%").
3. Review the per-group table to identify groups with low adoption.
4. Use the :guilabel:`Send reminder` action for individual users who have not
   yet registered.
5. Use the :guilabel:`Unlock` action to reset rate-limiting for locked-out users.

..  tip::

    Before moving a group from **Required** to **Enforced**, verify that the
    group's adoption percentage is at or near 100% in the dashboard. Users
    without passkeys in an **Enforced** group will see a mandatory interstitial
    on every login until they register.


Recovery procedures
===================

When a user loses access to their authenticator device (e.g. a lost phone or
broken YubiKey):

1. The user contacts an administrator.
2. The administrator opens :guilabel:`Admin Tools > Passkey Management` or uses
   the admin API to **revoke** the affected credential:

   ..  code-block:: text

      POST /typo3/ajax/passkeys/admin/remove
      Content-Type: application/json

      {"beUserUid": 123, "credentialUid": 456}

3. If the user is locked out, the administrator can **unlock** the account:

   ..  code-block:: text

      POST /typo3/ajax/passkeys/admin/unlock
      Content-Type: application/json

      {"beUserUid": 123, "username": "johndoe"}

4. The user logs in with their password (if password login is still available)
   and registers a new passkey.

..  important::

    If the user's group is set to **Enforced** and the user has no remaining
    passkeys, they can still log in with a password. The **Enforced** level
    only blocks password login for users who **have** registered passkeys.
    After revoking all credentials, the user can log in with a password and
    register a replacement.


MFA coexistence
===============

TYPO3 has built-in Multi-Factor Authentication (MFA) support since v11. The
passkey enforcement feature works alongside MFA:

- Passkeys and MFA are independent systems. A user can have both enabled.
- If MFA is required and passkey enforcement is active, both are evaluated.
  The MFA redirect takes precedence during login, and the passkey interstitial
  appears on subsequent requests.
- Passkeys already provide strong authentication (possession + biometric). In
  most scenarios, requiring both MFA and passkeys is unnecessary. Consider
  accepting passkeys as a sufficient authentication factor and disabling MFA
  requirements for groups that are at the **Enforced** level.

..  note::

    The interstitial middleware exempts MFA-related routes, so users are never
    blocked from completing their MFA challenge by the passkey setup page.
