/**
 * Passkey Banner - Encourage-stage adoption banner for TYPO3 Backend.
 *
 * Shows a dismissible info banner for backend users without passkeys
 * when their enforcement level is >= encourage. Fetches enforcement
 * status from the AJAX endpoint and persists dismissal in sessionStorage.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';

class PasskeyBanner {
  constructor() {
    DocumentService.ready().then(() => this.initialize());
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

  async initialize() {
    if (sessionStorage.getItem('nr-passkeys-banner-dismissed')) {
      return;
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_enforcement_status).get();
      const data = await response.resolve();

      if (data.requiresBanner) {
        this.showBanner(data);
      }
    } catch {
      // Silently fail - banner is non-critical
    }
  }

  showBanner(data) {
    const banner = document.createElement('div');
    banner.className = 'callout callout-info passkey-setup-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');

    const body = document.createElement('div');
    body.className = 'callout-body';
    body.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 1rem;';

    const message = document.createElement('span');
    message.textContent = data.gracePeriodRemainingDays > 0
      ? this.translate('js.banner.remaining', 'Passkeys are available for your account. You have %d days to set up passwordless login.').replace('%d', data.gracePeriodRemainingDays)
      : this.translate('js.banner.available', 'Passkeys are now available for your account. Set up passwordless login for faster, more secure access.');

    const actions = document.createElement('span');
    actions.style.cssText = 'white-space: nowrap;';

    const setupLink = document.createElement('a');
    setupLink.href = '#';
    setupLink.className = 'btn btn-sm btn-primary';
    setupLink.textContent = this.translate('js.banner.setup', 'Set up now');
    setupLink.style.marginRight = '0.5rem';
    setupLink.addEventListener('click', (e) => {
      e.preventDefault();
      top.TYPO3.ModuleMenu.App.showModule('user_setup');
    });

    const dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.className = 'btn btn-sm btn-default';
    dismissBtn.textContent = this.translate('js.banner.dismiss', 'Dismiss');
    dismissBtn.addEventListener('click', () => {
      banner.remove();
      sessionStorage.setItem('nr-passkeys-banner-dismissed', '1');
    });

    actions.appendChild(setupLink);
    actions.appendChild(dismissBtn);
    body.appendChild(message);
    body.appendChild(actions);
    banner.appendChild(body);

    const container = document.querySelector('.scaffold-content-module') || document.querySelector('.module');
    if (container) {
      container.prepend(banner);
    }
  }
}

export default new PasskeyBanner();
