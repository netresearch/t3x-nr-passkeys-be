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
        Notification.success('Enforcement updated', 'Group enforcement set to "' + enforcement + '".');
      } else {
        select.value = originalValue;
        Notification.error('Update failed', data.error || 'Unknown error.');
      }
    } catch (error) {
      select.value = originalValue;
      const message = error.response
        ? 'Server returned an error. Please try again.'
        : 'Network error. Please check your connection.';
      Notification.error('Update failed', message);
    } finally {
      select.disabled = false;
    }
  }

  async handleSendReminder(event) {
    const button = event.currentTarget;
    const beUserUid = parseInt(button.dataset.userUid, 10);
    const username = button.dataset.username;

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Sending...';

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_send_reminder).post({
        beUserUid: beUserUid,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        button.textContent = 'Sent';
        Notification.success('Reminder sent', 'Passkey setup reminder sent to "' + username + '".');
      } else {
        button.textContent = originalText;
        button.disabled = false;
        Notification.error('Reminder failed', data.error || 'Unknown error.');
      }
    } catch (error) {
      button.textContent = originalText;
      button.disabled = false;
      const message = error.response
        ? 'Server returned an error. Please try again.'
        : 'Network error. Please check your connection.';
      Notification.error('Reminder failed', message);
    }
  }
}

export default new PasskeyDashboard();
