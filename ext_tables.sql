CREATE TABLE tx_nrpasskeysbe_credential (
    be_user int(11) unsigned NOT NULL DEFAULT 0,
    credential_id varbinary(255) NOT NULL,
    public_key_cose blob NOT NULL,
    sign_count int(11) unsigned NOT NULL DEFAULT 0,
    user_handle varbinary(64) DEFAULT NULL,
    aaguid char(36) DEFAULT NULL,
    transports text DEFAULT NULL,
    label varchar(128) NOT NULL DEFAULT '',
    created_at int(11) unsigned NOT NULL DEFAULT 0,
    last_used_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_by int(11) unsigned NOT NULL DEFAULT 0,

    UNIQUE KEY credential_id (credential_id),
    KEY be_user (be_user)
);
