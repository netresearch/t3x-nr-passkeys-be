/**
 * Unit tests for the SHIPPED PasskeyBanner.js: DOM rendering, the translate
 * helper, dismiss/navigation behaviour and the initialize() flow that asks the
 * enforcement-status endpoint whether to show the banner.
 *
 * The module's constructor waits for DocumentService.ready(), which the stub
 * never resolves, so each test drives initialize() or showBanner() itself.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { requests, respondWith, resetRequests } from '@typo3/core/ajax/ajax-request.js';

const { default: banner } = await import('@netresearch/nr-passkeys-be/PasskeyBanner.js');

const STATUS_URL = '/typo3/ajax/passkeys/enforcement-status';

function resetBackend(overrides = {}) {
  globalThis.TYPO3 = {
    lang: {},
    settings: { ajaxUrls: { passkeys_enforcement_status: STATUS_URL } },
    ModuleMenu: { App: { showModule: vi.fn() } },
    ...overrides,
  };
}

function showBanner(data) {
  banner.showBanner(data);
  return document.querySelector('.passkey-setup-banner');
}

describe('translate helper', () => {
  beforeEach(() => {
    resetBackend();
  });

  it('should return fallback when TYPO3.lang is undefined', () => {
    globalThis.TYPO3 = {};
    expect(banner.translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });

  it('should return fallback when key is not in TYPO3.lang', () => {
    expect(banner.translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });

  it('should return translated value when key exists', () => {
    globalThis.TYPO3.lang = { 'js.banner.setup': 'Jetzt einrichten' };
    expect(banner.translate('js.banner.setup', 'Set up now')).toBe('Jetzt einrichten');
  });

  it('should return fallback when value is empty string (falsy)', () => {
    globalThis.TYPO3.lang = { 'js.banner.setup': '' };
    expect(banner.translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });
});

describe('showBanner DOM rendering', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    resetBackend();
    sessionStorage.clear();
  });

  it('should create banner with correct CSS classes', () => {
    const shown = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    expect(shown.className).toBe('callout callout-info passkey-setup-banner');
  });

  it('should set accessibility attributes', () => {
    const shown = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    expect(shown.getAttribute('role')).toBe('status');
    expect(shown.getAttribute('aria-live')).toBe('polite');
  });

  it('should prepend banner to .scaffold-content-module container', () => {
    const existing = document.createElement('p');
    container.appendChild(existing);
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
    expect(container.firstChild).toBe(found);
    expect(container.style.flexDirection).toBe('column');
  });

  it('should fall back to .t3js-scaffold-content-module when .scaffold-content-module is absent', () => {
    container.className = 't3js-scaffold-content-module';
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
  });

  it('should not insert a banner when no matching container exists', () => {
    container.className = 'unrelated';
    expect(() => banner.showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 })).not.toThrow();
    expect(document.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should show title when no grace period', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toBe('Passkeys available for your account');
  });

  it('should show description with passkey explanation', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const desc = container.querySelector('.passkey-banner-description');
    expect(desc.textContent).toContain('fingerprint, face, or security key');
    expect(desc.textContent).toContain('phishing');
  });

  it('should show help text with administrator contact', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const help = container.querySelector('.passkey-banner-help');
    expect(help.textContent).toBe('Need help? Contact your administrator.');
  });

  it('should show "Learn more" link to docs.typo3.org', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const link = container.querySelector('.passkey-banner-description a');
    expect(link).not.toBeNull();
    expect(link.textContent).toBe('Learn more');
    expect(link.href).toContain('docs.typo3.org/p/netresearch/nr-passkeys-be');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
  });

  it('should show countdown title when grace period is active', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 7 });
    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toContain('7 days remaining');
  });

  it('should replace %d placeholder with actual days in title', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 14 });
    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toContain('14 days');
    expect(title.textContent).not.toContain('%d');
  });

  it('should render "Set up now" button as primary', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    expect(setupBtn).not.toBeNull();
    expect(setupBtn.textContent).toBe('Set up now');
    expect(setupBtn.tagName).toBe('A');
    expect(setupBtn.getAttribute('href')).toBe('#');
  });

  it('should use theme classes instead of hardcoded inline colors', () => {
    const shown = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    // Colors must come from the core callout-info theming + backend.css so
    // the banner adapts to the v14 light/dark schemes.
    expect(shown.getAttribute('style')).toBeNull();
    expect(shown.querySelector('.passkey-banner-text')).not.toBeNull();
    expect(shown.querySelector('.passkey-banner-actions')).not.toBeNull();
    expect(shown.querySelector('.passkey-banner-description a').getAttribute('style')).toBeNull();
  });

  it('should render "Dismiss" button as default', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const dismissBtn = container.querySelector('.btn-default');
    expect(dismissBtn).not.toBeNull();
    expect(dismissBtn.textContent).toBe('Dismiss');
    expect(dismissBtn.tagName).toBe('BUTTON');
  });
});

describe('banner dismiss behavior', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    resetBackend();
    sessionStorage.clear();
  });

  it('should remove banner from DOM and restore the layout on dismiss click', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const dismissBtn = container.querySelector('.btn-default');
    dismissBtn.click();
    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
    expect(container.style.flexDirection).toBe('');
  });

  it('should set sessionStorage flag on dismiss keyed to nudgeUntil', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0, nudgeUntil: 1700000000 });
    const dismissBtn = container.querySelector('.btn-default');
    dismissBtn.click();
    expect(sessionStorage.getItem('nr-passkeys-banner-dismissed')).toBe('1700000000');
  });

  it('should set sessionStorage flag to 0 when no nudgeUntil', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const dismissBtn = container.querySelector('.btn-default');
    dismissBtn.click();
    expect(sessionStorage.getItem('nr-passkeys-banner-dismissed')).toBe('0');
  });
});

describe('banner "Set up now" navigation', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    resetBackend();
    sessionStorage.clear();
  });

  it('should call TYPO3 ModuleMenu.App.showModule on click', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    setupBtn.click();

    expect(globalThis.TYPO3.ModuleMenu.App.showModule).toHaveBeenCalledWith('user_setup');
  });

  it('should prevent default link behavior', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    const event = new Event('click', { cancelable: true });
    setupBtn.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
  });
});

describe('banner placement specificity', () => {
  beforeEach(() => {
    document.body.textContent = '';
    resetBackend();
    sessionStorage.clear();
  });

  it('should prefer .scaffold-content-module over .t3js-scaffold-content-module', () => {
    const t3jsDiv = document.createElement('div');
    t3jsDiv.className = 't3js-scaffold-content-module';
    document.body.appendChild(t3jsDiv);

    const scaffoldModuleDiv = document.createElement('div');
    scaffoldModuleDiv.className = 'scaffold-content-module';
    document.body.appendChild(scaffoldModuleDiv);

    banner.showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldModuleDiv.querySelector('.passkey-setup-banner')).not.toBeNull();
    expect(t3jsDiv.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should fall back to typo3-backend-module-router parent (v14) and let the router fill the height', () => {
    const parentDiv = document.createElement('div');
    document.body.appendChild(parentDiv);

    const router = document.createElement('typo3-backend-module-router');
    parentDiv.appendChild(router);

    banner.showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(parentDiv.querySelector('.passkey-setup-banner')).not.toBeNull();
    expect(router.style.flex).toBe('1 1 auto');
    expect(router.style.minHeight).toBe('0px');
  });

  it('should NOT place banner in .scaffold-content (flex-row parent)', () => {
    const scaffoldContent = document.createElement('div');
    scaffoldContent.className = 'scaffold-content';
    document.body.appendChild(scaffoldContent);

    banner.showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldContent.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should NOT place banner in .module-body (only exists in iframe)', () => {
    const moduleBody = document.createElement('div');
    moduleBody.className = 'module-body';
    document.body.appendChild(moduleBody);

    banner.showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(moduleBody.querySelector('.passkey-setup-banner')).toBeNull();
  });
});

describe('banner with TYPO3 language labels', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    resetBackend();
    sessionStorage.clear();
  });

  it('should use translated labels when available', () => {
    globalThis.TYPO3.lang = {
      'js.banner.title.available': 'Passkeys verfuegbar',
      'js.banner.description': 'Schneller und sicherer anmelden.',
      'js.banner.help': 'Hilfe? Fragen Sie Ihren Administrator.',
      'js.banner.learnMore': 'Mehr erfahren',
      'js.banner.setup': 'Jetzt einrichten',
      'js.banner.dismiss': 'Schliessen',
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toBe('Passkeys verfuegbar');

    const desc = container.querySelector('.passkey-banner-description');
    expect(desc.textContent).toContain('Schneller und sicherer anmelden.');

    const help = container.querySelector('.passkey-banner-help');
    expect(help.textContent).toBe('Hilfe? Fragen Sie Ihren Administrator.');

    const learnMoreLink = container.querySelector('.passkey-banner-description a');
    expect(learnMoreLink.textContent).toBe('Mehr erfahren');

    const setupBtn = container.querySelector('.btn-primary');
    expect(setupBtn.textContent).toBe('Jetzt einrichten');

    const dismissBtn = container.querySelector('.btn-default');
    expect(dismissBtn.textContent).toBe('Schliessen');
  });

  it('should use translated remaining-days title with replacement', () => {
    globalThis.TYPO3.lang = {
      'js.banner.title.remaining': 'Passkey-Einrichtung — noch %d Tage',
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 5 });

    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toBe('Passkey-Einrichtung — noch 5 Tage');
  });
});

describe('initialize() asks the enforcement-status endpoint', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    resetBackend();
    resetRequests();
    sessionStorage.clear();
  });

  it('should GET the enforcement-status URL', async () => {
    respondWith(() => ({ requiresBanner: false }));
    await banner.initialize();

    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe(STATUS_URL);
    expect(requests[0].method).toBe('GET');
  });

  it('should show the banner when the status requires it', async () => {
    respondWith(() => ({ requiresBanner: true, gracePeriodRemainingDays: 3 }));
    await banner.initialize();

    expect(container.querySelector('.passkey-setup-banner strong').textContent).toContain('3 days remaining');
  });

  it('should not show the banner when the status does not require it', async () => {
    respondWith(() => ({ requiresBanner: false }));
    await banner.initialize();

    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should stay hidden when the same nudge was dismissed in this session', async () => {
    sessionStorage.setItem('nr-passkeys-banner-dismissed', '1700000000');
    respondWith(() => ({ requiresBanner: true, gracePeriodRemainingDays: 0, nudgeUntil: 1700000000 }));
    await banner.initialize();

    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should stay hidden when dismissed without a nudge and there still is none', async () => {
    sessionStorage.setItem('nr-passkeys-banner-dismissed', '0');
    respondWith(() => ({ requiresBanner: true, gracePeriodRemainingDays: 0 }));
    await banner.initialize();

    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should reappear for a new nudge after an earlier one was dismissed', async () => {
    sessionStorage.setItem('nr-passkeys-banner-dismissed', '1700000000');
    respondWith(() => ({ requiresBanner: true, gracePeriodRemainingDays: 0, nudgeUntil: 1800000000 }));
    await banner.initialize();

    expect(container.querySelector('.passkey-setup-banner')).not.toBeNull();
  });

  it('should show nothing and not throw when the request fails', async () => {
    respondWith(() => {
      throw new Error('network down');
    });

    await expect(banner.initialize()).resolves.toBeUndefined();
    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
  });
});
