-- Copyright (c) 2025-2026 Netresearch DTT GmbH
-- SPDX-License-Identifier: GPL-2.0-or-later

CREATE TABLE tx_nrpasskeysbe_credential (
    be_user int(11) unsigned NOT NULL DEFAULT 0,
    credential_id varbinary(1024) NOT NULL,
    public_key_cose blob NOT NULL,
    backup_eligible tinyint(1) DEFAULT NULL,
    sign_count int(11) unsigned NOT NULL DEFAULT 0,
    user_handle varbinary(64) DEFAULT NULL,
    aaguid char(36) DEFAULT NULL,
    transports text DEFAULT NULL,
    -- credProps.rk from registration: 1 discoverable, 0 not, NULL unknown
    -- (registered before this was recorded, or authenticator stayed silent).
    -- A non-discoverable passkey cannot appear in the browser's autofill menu.
    discoverable tinyint(1) DEFAULT NULL,
    label varchar(128) NOT NULL DEFAULT '',
    created_at int(11) unsigned NOT NULL DEFAULT 0,
    last_used_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_by int(11) unsigned NOT NULL DEFAULT 0,

    UNIQUE KEY credential_id (credential_id),
    KEY be_user (be_user)
);

CREATE TABLE be_groups (
    passkey_suspended_until int(11) unsigned DEFAULT '0' NOT NULL,
    passkey_enforcement varchar(10) NOT NULL DEFAULT 'off',
    passkey_grace_period_days int(11) NOT NULL DEFAULT 14
);

CREATE TABLE be_users (
    passkey_grace_period_start int(11) unsigned NOT NULL DEFAULT 0,
    passkey_nudge_until int(11) unsigned NOT NULL DEFAULT 0
);

CREATE TABLE tx_nrpasskeysbe_consumed_token (
    token_hash varchar(64) DEFAULT '' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (token_hash),
    KEY expires_at (expires_at)
);

CREATE TABLE tx_nrpasskeysbe_rate_limit (
    key_hash varchar(64) DEFAULT '' NOT NULL,
    group_hash varchar(64) DEFAULT '' NOT NULL,
    attempts int(11) unsigned DEFAULT '0' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (key_hash),
    KEY group_hash (group_hash),
    KEY expires_at (expires_at)
);
