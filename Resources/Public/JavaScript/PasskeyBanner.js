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
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_enforcement_status).get();
      const data = await response.resolve();

      if (!data.requiresBanner) {
        return;
      }

      // Tie dismissal to the specific nudge/enforcement state so a new nudge
      // re-shows the banner even if the user dismissed a previous one.
      const dismissKey = 'nr-passkeys-banner-dismissed';
      const dismissed = sessionStorage.getItem(dismissKey);
      if (dismissed && dismissed === String(data.nudgeUntil || 0)) {
        return;
      }

      this.showBanner(data);
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

    const textWrapper = document.createElement('div');
    textWrapper.className = 'passkey-banner-text';

    const title = document.createElement('strong');
    title.textContent = data.gracePeriodRemainingDays > 0
      ? this.translate('js.banner.title.remaining', 'Passkey setup \u2014 %d days remaining').replace('%d', data.gracePeriodRemainingDays)
      : this.translate('js.banner.title.available', 'Passkeys available for your account');

    const description = document.createElement('div');
    description.className = 'passkey-banner-description';
    description.textContent = this.translate('js.banner.description',
      'Passkeys replace your password with fingerprint, face, or security key authentication \u2014 faster to use and resistant to phishing attacks.');

    const learnMore = document.createElement('a');
    learnMore.href = 'https://docs.typo3.org/p/netresearch/nr-passkeys-be/main/en-us/';
    learnMore.target = '_blank';
    learnMore.rel = 'noopener noreferrer';
    learnMore.textContent = this.translate('js.banner.learnMore', 'Learn more');
    description.appendChild(document.createTextNode(' '));
    description.appendChild(learnMore);

    const help = document.createElement('div');
    help.className = 'passkey-banner-help';
    help.textContent = this.translate('js.banner.help', 'Need help? Contact your administrator.');

    textWrapper.appendChild(title);
    textWrapper.appendChild(description);
    textWrapper.appendChild(help);

    const actions = document.createElement('span');
    actions.className = 'passkey-banner-actions';

    const setupLink = document.createElement('a');
    setupLink.href = '#';
    setupLink.className = 'btn btn-sm btn-primary';
    setupLink.textContent = this.translate('js.banner.setup', 'Set up now');
    setupLink.addEventListener('click', (e) => {
      e.preventDefault();
      top.TYPO3.ModuleMenu.App.showModule('user_setup');
    });

    const dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.className = 'btn btn-sm btn-default';
    dismissBtn.textContent = this.translate('js.banner.dismiss', 'Dismiss');

    actions.appendChild(setupLink);
    actions.appendChild(dismissBtn);
    body.appendChild(textWrapper);
    body.appendChild(actions);
    banner.appendChild(body);

    // Insert into the scaffold-content-module area (v12/v13) or the module
    // router's parent div (v14). The container uses display:flex and we switch
    // to column so the banner sits above the router at full width.
    const container = document.querySelector('.scaffold-content-module')
      || document.querySelector('.t3js-scaffold-content-module')
      || document.querySelector('typo3-backend-module-router')?.parentElement;
    if (container) {
      container.style.flexDirection = 'column';
      container.prepend(banner);

      // Ensure the module router fills remaining height
      const router = container.querySelector('typo3-backend-module-router');
      if (router) {
        router.style.flex = '1 1 auto';
        router.style.minHeight = '0';
      }

      dismissBtn.addEventListener('click', () => {
        banner.remove();
        container.style.flexDirection = '';
        sessionStorage.setItem('nr-passkeys-banner-dismissed', String(data.nudgeUntil || 0));
      });
    }
  }
}

export default new PasskeyBanner();
