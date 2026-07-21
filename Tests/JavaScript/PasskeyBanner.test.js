/**
 * Unit tests for PasskeyBanner.js DOM rendering and behavior.
 *
 * Tests the extracted showBanner logic, translate helper, and
 * dismiss/navigation behavior. The AJAX initialization is tested
 * via Playwright E2E tests.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

// --- Extracted logic from PasskeyBanner.js ---

function translate(key, fallback) {
  return (globalThis.TYPO3 && globalThis.TYPO3.lang && globalThis.TYPO3.lang[key]) || fallback;
}

function showBanner(data) {
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
    ? translate('js.banner.title.remaining', 'Passkey setup \u2014 %d days remaining').replace('%d', data.gracePeriodRemainingDays)
    : translate('js.banner.title.available', 'Passkeys available for your account');

  const description = document.createElement('div');
  description.className = 'passkey-banner-description';
  description.textContent = translate('js.banner.description',
    'Passkeys replace your password with fingerprint, face, or security key authentication \u2014 faster to use and resistant to phishing attacks.');

  const learnMore = document.createElement('a');
  learnMore.href = 'https://docs.typo3.org/p/netresearch/nr-passkeys-be/main/en-us/';
  learnMore.target = '_blank';
  learnMore.rel = 'noopener noreferrer';
  learnMore.textContent = translate('js.banner.learnMore', 'Learn more');
  description.appendChild(document.createTextNode(' '));
  description.appendChild(learnMore);

  const help = document.createElement('div');
  help.className = 'passkey-banner-help';
  help.textContent = translate('js.banner.help', 'Need help? Contact your administrator.');

  textWrapper.appendChild(title);
  textWrapper.appendChild(description);
  textWrapper.appendChild(help);

  const actions = document.createElement('span');
  actions.className = 'passkey-banner-actions';

  const setupLink = document.createElement('a');
  setupLink.href = '#';
  setupLink.className = 'btn btn-sm btn-primary';
  setupLink.textContent = translate('js.banner.setup', 'Set up now');
  setupLink.addEventListener('click', (e) => {
    e.preventDefault();
    if (typeof top !== 'undefined' && top.TYPO3 && top.TYPO3.ModuleMenu && top.TYPO3.ModuleMenu.App) {
      top.TYPO3.ModuleMenu.App.showModule('user_setup');
    }
  });

  const dismissBtn = document.createElement('button');
  dismissBtn.type = 'button';
  dismissBtn.className = 'btn btn-sm btn-default';
  dismissBtn.textContent = translate('js.banner.dismiss', 'Dismiss');

  actions.appendChild(setupLink);
  actions.appendChild(dismissBtn);
  body.appendChild(textWrapper);
  body.appendChild(actions);
  banner.appendChild(body);

  const container = document.querySelector('.scaffold-content-module')
    || document.querySelector('.t3js-scaffold-content-module')
    || document.querySelector('typo3-backend-module-router')?.parentElement;
  if (container) {
    container.style.flexDirection = 'column';
    container.prepend(banner);

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

  return banner;
}

// --- Tests ---

describe('translate helper', () => {
  beforeEach(() => {
    delete globalThis.TYPO3;
  });

  it('should return fallback when TYPO3 is undefined', () => {
    expect(translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });

  it('should return fallback when TYPO3.lang is undefined', () => {
    globalThis.TYPO3 = {};
    expect(translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });

  it('should return fallback when key is not in TYPO3.lang', () => {
    globalThis.TYPO3 = { lang: {} };
    expect(translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });

  it('should return translated value when key exists', () => {
    globalThis.TYPO3 = { lang: { 'js.banner.setup': 'Jetzt einrichten' } };
    expect(translate('js.banner.setup', 'Set up now')).toBe('Jetzt einrichten');
  });

  it('should return fallback when value is empty string (falsy)', () => {
    globalThis.TYPO3 = { lang: { 'js.banner.setup': '' } };
    expect(translate('js.banner.setup', 'Set up now')).toBe('Set up now');
  });
});

describe('showBanner DOM rendering', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'scaffold-content-module';
    document.body.appendChild(container);
    delete globalThis.TYPO3;
    sessionStorage.clear();
  });

  it('should create banner with correct CSS classes', () => {
    const banner = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    expect(banner.className).toBe('callout callout-info passkey-setup-banner');
  });

  it('should set accessibility attributes', () => {
    const banner = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    expect(banner.getAttribute('role')).toBe('status');
    expect(banner.getAttribute('aria-live')).toBe('polite');
  });

  it('should prepend banner to .scaffold-content-module container', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
    expect(container.firstChild).toBe(found);
  });

  it('should fall back to .t3js-scaffold-content-module when .scaffold-content-module is absent', () => {
    container.className = 't3js-scaffold-content-module';
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
  });

  it('should not throw when no matching container exists', () => {
    container.className = 'unrelated';
    expect(() => showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 })).not.toThrow();
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
    const banner = showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    // Colors must come from the core callout-info theming + backend.css so
    // the banner adapts to the v14 light/dark schemes.
    expect(banner.getAttribute('style')).toBeNull();
    expect(banner.querySelector('.passkey-banner-text')).not.toBeNull();
    expect(banner.querySelector('.passkey-banner-actions')).not.toBeNull();
    expect(banner.querySelector('.passkey-banner-description a').getAttribute('style')).toBeNull();
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
    delete globalThis.TYPO3;
    sessionStorage.clear();
  });

  it('should remove banner from DOM on dismiss click', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const dismissBtn = container.querySelector('.btn-default');
    dismissBtn.click();
    expect(container.querySelector('.passkey-setup-banner')).toBeNull();
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
    delete globalThis.TYPO3;
    sessionStorage.clear();
  });

  it('should call TYPO3 ModuleMenu.App.showModule on click', () => {
    const showModuleMock = vi.fn();
    globalThis.TYPO3 = {
      ModuleMenu: { App: { showModule: showModuleMock } },
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    setupBtn.click();

    expect(showModuleMock).toHaveBeenCalledWith('user_setup');
  });

  it('should prevent default link behavior', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    const event = new Event('click', { cancelable: true });
    setupBtn.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
  });

  it('should not throw when TYPO3.ModuleMenu is unavailable', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    expect(() => setupBtn.click()).not.toThrow();
  });
});

describe('banner placement specificity', () => {
  beforeEach(() => {
    document.body.textContent = '';
    delete globalThis.TYPO3;
    sessionStorage.clear();
  });

  it('should prefer .scaffold-content-module over .t3js-scaffold-content-module', () => {
    const t3jsDiv = document.createElement('div');
    t3jsDiv.className = 't3js-scaffold-content-module';
    document.body.appendChild(t3jsDiv);

    const scaffoldModuleDiv = document.createElement('div');
    scaffoldModuleDiv.className = 'scaffold-content-module';
    document.body.appendChild(scaffoldModuleDiv);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldModuleDiv.querySelector('.passkey-setup-banner')).not.toBeNull();
    expect(t3jsDiv.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should fall back to typo3-backend-module-router parent (v14)', () => {
    const parentDiv = document.createElement('div');
    document.body.appendChild(parentDiv);

    const router = document.createElement('typo3-backend-module-router');
    parentDiv.appendChild(router);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(parentDiv.querySelector('.passkey-setup-banner')).not.toBeNull();
  });

  it('should NOT place banner in .scaffold-content (flex-row parent)', () => {
    const scaffoldContent = document.createElement('div');
    scaffoldContent.className = 'scaffold-content';
    document.body.appendChild(scaffoldContent);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldContent.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should NOT place banner in .module-body (only exists in iframe)', () => {
    const moduleBody = document.createElement('div');
    moduleBody.className = 'module-body';
    document.body.appendChild(moduleBody);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

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
    sessionStorage.clear();
  });

  it('should use translated labels when available', () => {
    globalThis.TYPO3 = {
      lang: {
        'js.banner.title.available': 'Passkeys verfuegbar',
        'js.banner.description': 'Schneller und sicherer anmelden.',
        'js.banner.help': 'Hilfe? Fragen Sie Ihren Administrator.',
        'js.banner.learnMore': 'Mehr erfahren',
        'js.banner.setup': 'Jetzt einrichten',
        'js.banner.dismiss': 'Schliessen',
      },
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
    globalThis.TYPO3 = {
      lang: {
        'js.banner.title.remaining': 'Passkey-Einrichtung — noch %d Tage',
      },
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 5 });

    const title = container.querySelector('.passkey-setup-banner strong');
    expect(title.textContent).toBe('Passkey-Einrichtung — noch 5 Tage');
  });
});
