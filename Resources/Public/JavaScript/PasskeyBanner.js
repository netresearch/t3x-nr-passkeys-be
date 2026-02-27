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

    const body = document.createElement('div');
    body.className = 'callout-body';
    body.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 1rem;';

    const message = document.createElement('span');
    message.textContent = data.gracePeriodRemainingDays > 0
      ? 'Passkeys are available for your account. You have ' + data.gracePeriodRemainingDays + ' days to set up passwordless login.'
      : 'Passkeys are now available for your account. Set up passwordless login for faster, more secure access.';

    const actions = document.createElement('span');
    actions.style.cssText = 'white-space: nowrap;';

    const setupLink = document.createElement('a');
    setupLink.href = (TYPO3.settings.FormEngine && TYPO3.settings.FormEngine.moduleUrl)
      ? TYPO3.settings.FormEngine.moduleUrl
      : '/typo3/setup/';
    setupLink.className = 'btn btn-sm btn-primary';
    setupLink.textContent = 'Set up now';
    setupLink.style.marginRight = '0.5rem';

    const dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.className = 'btn btn-sm btn-default';
    dismissBtn.textContent = 'Dismiss';
    dismissBtn.addEventListener('click', () => {
      banner.remove();
      sessionStorage.setItem('nr-passkeys-banner-dismissed', '1');
    });

    actions.appendChild(setupLink);
    actions.appendChild(dismissBtn);
    body.appendChild(message);
    body.appendChild(actions);
    banner.appendChild(body);

    const scaffold = document.querySelector('.scaffold-content') || document.querySelector('.module');
    if (scaffold) {
      scaffold.prepend(banner);
    }
  }
}

export default new PasskeyBanner();
