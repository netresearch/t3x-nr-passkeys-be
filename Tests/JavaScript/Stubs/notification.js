/**
 * Stand-in for @typo3/backend/notification.js (see vitest.config.mjs).
 * Records every notification a module raises in `notifications`.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
export const notifications = [];

const record = (severity) => (title, message) => {
  notifications.push({ severity, title, message });
};

export default {
  success: record('success'),
  info: record('info'),
  warning: record('warning'),
  error: record('error'),
};
