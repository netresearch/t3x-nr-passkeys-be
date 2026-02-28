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
  body.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 1rem;';

  const message = document.createElement('span');
  message.textContent = data.gracePeriodRemainingDays > 0
    ? translate('js.banner.remaining', 'Passkeys are available for your account. You have %d days to set up passwordless login.').replace('%d', data.gracePeriodRemainingDays)
    : translate('js.banner.available', 'Passkeys are now available for your account. Set up passwordless login for faster, more secure access.');

  const actions = document.createElement('span');
  actions.style.cssText = 'white-space: nowrap;';

  const setupLink = document.createElement('a');
  setupLink.href = '#';
  setupLink.className = 'btn btn-sm btn-primary';
  setupLink.textContent = translate('js.banner.setup', 'Set up now');
  setupLink.style.marginRight = '0.5rem';
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
  dismissBtn.addEventListener('click', () => {
    banner.remove();
    sessionStorage.setItem('nr-passkeys-banner-dismissed', String(data.nudgeUntil || 0));
  });

  actions.appendChild(setupLink);
  actions.appendChild(dismissBtn);
  body.appendChild(message);
  body.appendChild(actions);
  banner.appendChild(body);

  const container = document.querySelector('.module-body')
    || document.querySelector('.scaffold-content-module-body')
    || document.querySelector('.module');
  if (container) {
    container.prepend(banner);
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
    container.className = 'module-body';
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

  it('should prepend banner to .module-body container', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
    expect(container.firstChild).toBe(found);
  });

  it('should fall back to .module container when .module-body is absent', () => {
    container.className = 'module';
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
  });

  it('should fall back to .scaffold-content-module-body when .module-body is absent', () => {
    container.className = 'scaffold-content-module-body';
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const found = container.querySelector('.passkey-setup-banner');
    expect(found).not.toBeNull();
  });

  it('should not throw when no matching container exists', () => {
    container.className = 'unrelated';
    expect(() => showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 })).not.toThrow();
  });

  it('should show generic message when no grace period', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const span = container.querySelector('.passkey-setup-banner span');
    expect(span.textContent).toContain('Passkeys are now available');
    expect(span.textContent).toContain('passwordless login');
  });

  it('should show countdown message when grace period is active', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 7 });
    const span = container.querySelector('.passkey-setup-banner span');
    expect(span.textContent).toContain('7 days');
  });

  it('should replace %d placeholder with actual days', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 14 });
    const span = container.querySelector('.passkey-setup-banner span');
    expect(span.textContent).toContain('14 days');
    expect(span.textContent).not.toContain('%d');
  });

  it('should render "Set up now" button as primary', () => {
    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });
    const setupBtn = container.querySelector('.btn-primary');
    expect(setupBtn).not.toBeNull();
    expect(setupBtn.textContent).toBe('Set up now');
    expect(setupBtn.tagName).toBe('A');
    expect(setupBtn.getAttribute('href')).toBe('#');
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
    container.className = 'module-body';
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
    container.className = 'module-body';
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

  it('should prefer .module-body over .module', () => {
    const moduleDiv = document.createElement('div');
    moduleDiv.className = 'module';
    document.body.appendChild(moduleDiv);

    const moduleBodyDiv = document.createElement('div');
    moduleBodyDiv.className = 'module-body';
    document.body.appendChild(moduleBodyDiv);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(moduleBodyDiv.querySelector('.passkey-setup-banner')).not.toBeNull();
    expect(moduleDiv.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should NOT place banner in .scaffold-content (flex-row parent)', () => {
    const scaffoldContent = document.createElement('div');
    scaffoldContent.className = 'scaffold-content';
    document.body.appendChild(scaffoldContent);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldContent.querySelector('.passkey-setup-banner')).toBeNull();
  });

  it('should NOT place banner in .scaffold-content-module (outer wrapper)', () => {
    const scaffoldModule = document.createElement('div');
    scaffoldModule.className = 'scaffold-content-module';
    document.body.appendChild(scaffoldModule);

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    expect(scaffoldModule.querySelector('.passkey-setup-banner')).toBeNull();
  });
});

describe('banner with TYPO3 language labels', () => {
  let container;

  beforeEach(() => {
    document.body.textContent = '';
    container = document.createElement('div');
    container.className = 'module-body';
    document.body.appendChild(container);
    sessionStorage.clear();
  });

  it('should use translated labels when available', () => {
    globalThis.TYPO3 = {
      lang: {
        'js.banner.available': 'Passkeys sind jetzt verfuegbar.',
        'js.banner.setup': 'Jetzt einrichten',
        'js.banner.dismiss': 'Schliessen',
      },
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 0 });

    const span = container.querySelector('.passkey-setup-banner span');
    expect(span.textContent).toBe('Passkeys sind jetzt verfuegbar.');

    const setupBtn = container.querySelector('.btn-primary');
    expect(setupBtn.textContent).toBe('Jetzt einrichten');

    const dismissBtn = container.querySelector('.btn-default');
    expect(dismissBtn.textContent).toBe('Schliessen');
  });

  it('should use translated remaining-days label with replacement', () => {
    globalThis.TYPO3 = {
      lang: {
        'js.banner.remaining': 'Sie haben noch %d Tage.',
      },
    };

    showBanner({ requiresBanner: true, gracePeriodRemainingDays: 5 });

    const span = container.querySelector('.passkey-setup-banner span');
    expect(span.textContent).toBe('Sie haben noch 5 Tage.');
  });
});
