/**
 * Passkey Dashboard - Interactive admin dashboard for passkey management.
 *
 * Handles enforcement-level dropdown changes (AJAX POST) and
 * reminder button clicks for users without passkeys.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import DocumentService from '@typo3/core/document-service.js';

class PasskeyDashboard {
  constructor() {
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.bindEnforcementSelects();
    this.bindReminderButtons();
    this.bindUnlockButtons();
  }

  /**
   * Translate a key from the TYPO3 inline language labels with a fallback.
   *
   * @param {string} key
   * @param {string} fallback
   * @returns {string}
   */
  translate(key, fallback) {
    return (TYPO3.lang && TYPO3.lang[key]) || fallback;
  }

  bindEnforcementSelects() {
    const selects = document.querySelectorAll('.passkey-enforcement-select');
    selects.forEach((select) => {
      select.addEventListener('change', (event) => this.handleEnforcementChange(event));
    });
  }

  bindReminderButtons() {
    const buttons = document.querySelectorAll('.passkey-send-reminder');
    buttons.forEach((button) => {
      button.addEventListener('click', (event) => this.handleSendReminder(event));
    });
  }

  async handleEnforcementChange(event) {
    const select = event.target;
    const groupUid = parseInt(select.dataset.groupUid, 10);
    const enforcement = select.value;
    const originalValue = select.dataset.originalValue;

    select.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_update_enforcement).post({
        groupUid: groupUid,
        enforcement: enforcement,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        select.dataset.originalValue = enforcement;
        Notification.success(
          this.translate('js.enforcement.updated', 'Enforcement updated'),
          this.translate('js.enforcement.setTo', 'Group enforcement set to "%s".').replace('%s', enforcement),
        );
      } else {
        select.value = originalValue;
        Notification.error(
          this.translate('js.enforcement.failed', 'Update failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      select.value = originalValue;
      const message = error.response
        ? this.translate('js.error.server', 'Server returned an error. Please try again.')
        : this.translate('js.error.network', 'Network error. Please check your connection.');
      Notification.error(this.translate('js.enforcement.failed', 'Update failed'), message);
    } finally {
      select.disabled = false;
    }
  }

  bindUnlockButtons() {
    const buttons = document.querySelectorAll('.passkey-unlock-user');
    buttons.forEach((button) => {
      button.addEventListener('click', (event) => this.handleUnlockUser(event));
    });
  }

  async handleUnlockUser(event) {
    const button = event.currentTarget;
    const beUserUid = parseInt(button.dataset.userUid, 10);
    const username = button.dataset.username;

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = this.translate('js.unlock.progress', 'Unlocking...');

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_unlock).post({
        beUserUid: beUserUid,
        username: username,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        button.textContent = this.translate('js.unlock.done', 'Unlocked');
        Notification.success(
          this.translate('js.unlock.success', 'Account unlocked'),
          this.translate('js.unlock.message', 'Rate limiter reset for "%s".').replace('%s', username),
        );
      } else {
        button.textContent = originalText;
        button.disabled = false;
        Notification.error(
          this.translate('js.unlock.failed', 'Unlock failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      button.textContent = originalText;
      button.disabled = false;
      const message = error.response
        ? this.translate('js.error.server', 'Server returned an error. Please try again.')
        : this.translate('js.error.network', 'Network error. Please check your connection.');
      Notification.error(this.translate('js.unlock.failed', 'Unlock failed'), message);
    }
  }

  async handleSendReminder(event) {
    const button = event.currentTarget;
    const beUserUid = parseInt(button.dataset.userUid, 10);
    const username = button.dataset.username;

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = this.translate('js.reminder.progress', 'Sending...');

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_send_reminder).post({
        beUserUid: beUserUid,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        button.textContent = this.translate('js.reminder.done', 'Sent');
        Notification.success(
          this.translate('js.reminder.success', 'Reminder sent'),
          this.translate('js.reminder.message', 'Passkey setup reminder sent to "%s".').replace('%s', username),
        );
      } else {
        button.textContent = originalText;
        button.disabled = false;
        Notification.error(
          this.translate('js.reminder.failed', 'Reminder failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      button.textContent = originalText;
      button.disabled = false;
      const message = error.response
        ? this.translate('js.error.server', 'Server returned an error. Please try again.')
        : this.translate('js.error.network', 'Network error. Please check your connection.');
      Notification.error(this.translate('js.reminder.failed', 'Reminder failed'), message);
    }
  }
}

export default new PasskeyDashboard();
