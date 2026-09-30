/**
 * Stand-in for @typo3/backend/modal.js (see vitest.config.mjs).
 *
 * Modal.show() records the dialog in `modals` instead of rendering it; a test
 * presses one of its buttons with `press(modal, name)`, which runs the
 * button's trigger the way the backend does when the user clicks it.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
export const modals = [];

export function press(modal, name) {
  const button = modal.buttons.find((candidate) => candidate.name === name);
  if (!button) {
    throw new Error(`Modal has no button "${name}"`);
  }
  button.trigger(new Event('click'), modal);
}

export default {
  show(title, content, severity, buttons) {
    const modal = {
      title,
      content,
      severity,
      buttons,
      hidden: false,
      hideModal() {
        this.hidden = true;
      },
    };
    modals.push(modal);
    return modal;
  },
};
