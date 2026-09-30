/**
 * Stand-in for @typo3/core/event/regular-event.js (see vitest.config.mjs):
 * bindTo() attaches the handler as a plain DOM event listener.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
export default class RegularEvent {
  constructor(eventName, callback) {
    this.eventName = eventName;
    this.callback = callback;
  }

  bindTo(element) {
    element.addEventListener(this.eventName, this.callback);
  }
}
