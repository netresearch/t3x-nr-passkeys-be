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
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { sudoModeInterceptor } from '@typo3/backend/security/sudo-mode-interceptor.js';

class PasskeyDashboard {
  constructor() {
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.bindEnforcementSelects();
    this.bindReminderButtons();
    this.bindUnlockButtons();
    this.bindClearNudgeButtons();
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

  /**
   * Extract an error message from a failed AjaxResponse.
   *
   * @param {*} error
   * @returns {string}
   */
  async extractErrorMessage(error) {
    try {
      if (error && typeof error.resolve === 'function') {
        const data = await error.resolve();
        if (data && data.error) {
          return data.error;
        }
      }
    } catch {
      // Response body could not be parsed; fall through to default message
    }

    if (error && error.response) {
      return this.translate('js.error.server', 'Server returned an error (status ' + error.response.status + '). Please try again.');
    }

    return this.translate('js.error.network', 'Network error. Please check your connection.');
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

  handleEnforcementChange(event) {
    const select = event.target;
    const groupUid = parseInt(select.dataset.groupUid, 10);
    const enforcement = select.value;
    const originalValue = select.dataset.originalValue;

    // Escalating to a level that can block backend login (Required/Enforced) is a
    // lockout risk for everyone in the group, so require explicit confirmation
    // before applying. Off/Encourage apply immediately.
    if ((enforcement === 'required' || enforcement === 'enforced') && enforcement !== originalValue) {
      Modal.show(
        this.translate('js.enforcement.confirm.title', 'Confirm enforcement change'),
        this.translate(
          'js.enforcement.confirm.body',
          'Setting this group to "%s" can block backend login for its members until they register a passkey. Continue?',
        ).replace('%s', enforcement),
        SeverityEnum.warning,
        [
          {
            text: this.translate('js.button.cancel', 'Cancel'),
            active: true,
            btnClass: 'btn-default',
            name: 'cancel',
            trigger: (e, modal) => {
              select.value = originalValue;
              modal.hideModal();
            },
          },
          {
            text: this.translate('js.enforcement.confirm.ok', 'Yes, change enforcement'),
            btnClass: 'btn-warning',
            name: 'confirm',
            trigger: (e, modal) => {
              modal.hideModal();
              this.performEnforcementChange(select, groupUid, enforcement, originalValue);
            },
          },
        ],
      );
      return;
    }

    this.performEnforcementChange(select, groupUid, enforcement, originalValue);
  }

  async performEnforcementChange(select, groupUid, enforcement, originalValue) {
    select.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_update_enforcement).addMiddleware(sudoModeInterceptor).post({
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
      const message = await this.extractErrorMessage(error);
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
    button.textContent = this.translate('js.unlock.progress', 'Resetting...');

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_unlock).addMiddleware(sudoModeInterceptor).post({
        beUserUid: beUserUid,
        username: username,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        button.textContent = this.translate('js.unlock.done', 'Reset');
        Notification.success(
          this.translate('js.unlock.success', 'Login lock reset'),
          this.translate('js.unlock.message', 'Failed login attempt counter reset for "%s".').replace('%s', username),
        );
      } else {
        button.textContent = originalText;
        button.disabled = false;
        Notification.error(
          this.translate('js.unlock.failed', 'Reset failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      button.textContent = originalText;
      button.disabled = false;
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.unlock.failed', 'Reset failed'), message);
    }
  }

  bindClearNudgeButtons() {
    const buttons = document.querySelectorAll('.passkey-clear-nudge');
    buttons.forEach((button) => {
      button.addEventListener('click', (event) => this.handleClearNudge(event));
    });
  }

  async handleClearNudge(event) {
    const button = event.currentTarget;
    const beUserUid = parseInt(button.dataset.userUid, 10);
    const username = button.dataset.username;

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = this.translate('js.clearNudge.progress', 'Clearing...');

    const url = TYPO3.settings.ajaxUrls.passkeys_admin_clear_nudge;
    if (!url) {
      button.textContent = originalText;
      button.disabled = false;
      Notification.error(
        this.translate('js.clearNudge.failed', 'Clear failed'),
        'AJAX route not available. Please flush all TYPO3 caches and reload.',
      );
      return;
    }

    try {
      const response = await new AjaxRequest(url).addMiddleware(sudoModeInterceptor).post({
        beUserUid: beUserUid,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        button.textContent = this.translate('js.clearNudge.done', 'Cleared');
        Notification.success(
          this.translate('js.clearNudge.success', 'Nudge cleared'),
          this.translate('js.clearNudge.message', 'Passkey nudge cleared for "%s".').replace('%s', username),
        );
      } else {
        button.textContent = originalText;
        button.disabled = false;
        Notification.error(
          this.translate('js.clearNudge.failed', 'Clear failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      button.textContent = originalText;
      button.disabled = false;
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.clearNudge.failed', 'Clear failed'), message);
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
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_send_reminder).addMiddleware(sudoModeInterceptor).post({
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
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.reminder.failed', 'Reminder failed'), message);
    }
  }
}

export default new PasskeyDashboard();
