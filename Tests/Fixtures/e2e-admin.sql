-- Copyright (c) 2025-2026 Netresearch DTT GmbH
-- SPDX-License-Identifier: GPL-2.0-or-later

-- Import once per test database. The credential only supplies a dashboard row.
START TRANSACTION;
INSERT INTO be_groups (title) VALUES ('E2E passkey enforcement');
INSERT INTO be_users (username, password, usergroup, passkey_nudge_until)
VALUES ('e2e_passkey_reminder', '!', LAST_INSERT_ID(), UNIX_TIMESTAMP() + 86400);
INSERT INTO be_users (username, password) VALUES ('e2e_passkey_management', '!');
INSERT INTO tx_nrpasskeysbe_credential (be_user, credential_id, public_key_cose, label)
VALUES (LAST_INSERT_ID(), 'e2e-admin-display-id', 'display-only', 'E2E management key');
COMMIT;
