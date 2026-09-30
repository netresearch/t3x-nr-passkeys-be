/**
 * Unit tests for the SHIPPED PasskeyDashboard.js (admin module): the
 * enforcement select with its confirmation for blocking levels, and the
 * reminder, unlock and clear-nudge buttons. Each action must POST to its
 * route through the sudo-mode interceptor and report the outcome.
 *
 * The module's constructor waits for DocumentService.ready(), which the stub
 * never resolves, so each test builds the markup and calls initialize().
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { requests, respondWith, resetRequests } from '@typo3/core/ajax/ajax-request.js';
import { notifications } from '@typo3/backend/notification.js';
import { modals, press } from '@typo3/backend/modal.js';
import { sudoModeInterceptor } from '@typo3/backend/security/sudo-mode-interceptor.js';

const { default: dashboard } = await import('@netresearch/nr-passkeys-be/PasskeyDashboard.js');

const URLS = {
  passkeys_admin_update_enforcement: '/ajax/enforcement',
  passkeys_admin_unlock: '/ajax/unlock',
  passkeys_admin_clear_nudge: '/ajax/clear-nudge',
  passkeys_admin_send_reminder: '/ajax/reminder',
};

const MARKUP = `
  <select class="passkey-enforcement-select" data-group-uid="7" data-original-value="off">
    <option value="off">off</option>
    <option value="encourage">encourage</option>
    <option value="required">required</option>
    <option value="enforced">enforced</option>
  </select>
  <button class="passkey-send-reminder" data-user-uid="11" data-username="alice">Send reminder</button>
  <button class="passkey-unlock-user" data-user-uid="12" data-username="bob">Unlock</button>
  <button class="passkey-clear-nudge" data-user-uid="13" data-username="carol">Clear nudge</button>
`;

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function render() {
  const parsed = new DOMParser().parseFromString(MARKUP, 'text/html');
  document.body.replaceChildren(...parsed.body.childNodes);
  dashboard.initialize();
}

function changeEnforcement(value) {
  const select = document.querySelector('.passkey-enforcement-select');
  select.value = value;
  select.dispatchEvent(new Event('change'));
  return select;
}

beforeEach(() => {
  globalThis.TYPO3 = { lang: {}, settings: { ajaxUrls: { ...URLS } } };
  resetRequests();
  notifications.length = 0;
  modals.length = 0;
  render();
});

describe('enforcement select', () => {
  it('applies a non-blocking level at once and records it as the new original value', async () => {
    const select = changeEnforcement('encourage');
    expect(select.disabled).toBe(true);
    await flush();

    expect(modals).toHaveLength(0);
    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe('/ajax/enforcement');
    expect(requests[0].method).toBe('POST');
    expect(requests[0].body).toEqual({ groupUid: 7, enforcement: 'encourage' });
    expect(requests[0].middlewares).toContain(sudoModeInterceptor);
    expect(select.dataset.originalValue).toBe('encourage');
    expect(select.disabled).toBe(false);
    expect(notifications).toEqual([
      { severity: 'success', title: 'Enforcement updated', message: 'Group enforcement set to "encourage".' },
    ]);
  });

  it('asks for confirmation before a level that can block login, and cancelling restores the old value', () => {
    const select = changeEnforcement('required');

    expect(requests).toHaveLength(0);
    expect(modals).toHaveLength(1);
    expect(modals[0].content).toContain('"required"');

    press(modals[0], 'cancel');

    expect(select.value).toBe('off');
    expect(modals[0].hidden).toBe(true);
    expect(requests).toHaveLength(0);
  });

  it('applies a blocking level once confirmed', async () => {
    changeEnforcement('enforced');
    press(modals[0], 'confirm');
    await flush();

    expect(modals[0].hidden).toBe(true);
    expect(requests).toHaveLength(1);
    expect(requests[0].body).toEqual({ groupUid: 7, enforcement: 'enforced' });
  });

  it('does not ask again when the blocking level is already the stored one', async () => {
    const select = document.querySelector('.passkey-enforcement-select');
    select.dataset.originalValue = 'required';
    changeEnforcement('required');
    await flush();

    expect(modals).toHaveLength(0);
    expect(requests).toHaveLength(1);
  });

  it('reverts the select and reports the server error when the update is refused', async () => {
    respondWith(() => ({ status: 'error', error: 'Group not found' }));
    const select = changeEnforcement('encourage');
    await flush();

    expect(select.value).toBe('off');
    expect(select.dataset.originalValue).toBe('off');
    expect(notifications).toEqual([{ severity: 'error', title: 'Update failed', message: 'Group not found' }]);
  });

  it('falls back to a generic message when a refusal carries no error text', async () => {
    respondWith(() => ({ status: 'error' }));
    changeEnforcement('encourage');
    await flush();

    expect(notifications[0].message).toBe('Unknown error.');
  });

  it('reverts the select and reports the error body of a failed request', async () => {
    respondWith(() => {
      throw { response: { status: 403 }, resolve: async () => ({ error: 'Sudo mode required' }) };
    });
    const select = changeEnforcement('encourage');
    await flush();
    await flush();

    expect(select.value).toBe('off');
    expect(select.disabled).toBe(false);
    expect(notifications).toEqual([{ severity: 'error', title: 'Update failed', message: 'Sudo mode required' }]);
  });
});

describe('error messages of failed requests', () => {
  it('names the HTTP status when the error body cannot be read', async () => {
    const message = await dashboard.extractErrorMessage({
      response: { status: 500 },
      resolve: async () => {
        throw new SyntaxError('not JSON');
      },
    });

    expect(message).toBe('Server returned an error (status 500). Please try again.');
  });

  it('reports a network error when there is no response at all', async () => {
    expect(await dashboard.extractErrorMessage(new TypeError('Failed to fetch'))).toBe(
      'Network error. Please check your connection.',
    );
  });

  it('uses the translated labels when the backend provides them', async () => {
    globalThis.TYPO3.lang = { 'js.error.network': 'Netzwerkfehler.' };

    expect(await dashboard.extractErrorMessage(undefined)).toBe('Netzwerkfehler.');
  });
});

const buttonCases = [
  {
    name: 'reminder',
    selector: '.passkey-send-reminder',
    url: '/ajax/reminder',
    body: { beUserUid: 11 },
    done: 'Sent',
    success: { title: 'Reminder sent', message: 'Passkey setup reminder sent to "alice".' },
    failedTitle: 'Reminder failed',
  },
  {
    name: 'unlock',
    selector: '.passkey-unlock-user',
    url: '/ajax/unlock',
    body: { beUserUid: 12, username: 'bob' },
    done: 'Reset',
    success: { title: 'Login lock reset', message: 'Failed login attempt counter reset for "bob".' },
    failedTitle: 'Reset failed',
  },
  {
    name: 'clear nudge',
    selector: '.passkey-clear-nudge',
    url: '/ajax/clear-nudge',
    body: { beUserUid: 13 },
    done: 'Cleared',
    success: { title: 'Nudge cleared', message: 'Passkey nudge cleared for "carol".' },
    failedTitle: 'Clear failed',
  },
];

describe.each(buttonCases)('$name button', ({ selector, url, body, done, success, failedTitle }) => {
  it('posts through the sudo-mode interceptor and reports success', async () => {
    const button = document.querySelector(selector);
    button.click();
    expect(button.disabled).toBe(true);
    await flush();

    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe(url);
    expect(requests[0].method).toBe('POST');
    expect(requests[0].body).toEqual(body);
    expect(requests[0].middlewares).toContain(sudoModeInterceptor);
    expect(button.textContent).toBe(done);
    expect(button.disabled).toBe(true);
    expect(notifications).toEqual([{ severity: 'success', ...success }]);
  });

  it('restores the button and reports a refusal', async () => {
    respondWith(() => ({ status: 'error', error: 'No such user' }));
    const button = document.querySelector(selector);
    const label = button.textContent;
    button.click();
    await flush();

    expect(button.textContent).toBe(label);
    expect(button.disabled).toBe(false);
    expect(notifications).toEqual([{ severity: 'error', title: failedTitle, message: 'No such user' }]);
  });

  it('falls back to a generic message when a refusal carries no error text', async () => {
    respondWith(() => ({ status: 'error' }));
    document.querySelector(selector).click();
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: failedTitle, message: 'Unknown error.' }]);
  });

  it('restores the button and reports a failed request', async () => {
    respondWith(() => {
      throw { response: { status: 502 } };
    });
    const button = document.querySelector(selector);
    const label = button.textContent;
    button.click();
    await flush();
    await flush();

    expect(button.textContent).toBe(label);
    expect(button.disabled).toBe(false);
    expect(notifications).toEqual([
      { severity: 'error', title: failedTitle, message: 'Server returned an error (status 502). Please try again.' },
    ]);
  });
});

describe('clear nudge without its AJAX route', () => {
  it('sends nothing and tells the admin to flush the caches', async () => {
    delete globalThis.TYPO3.settings.ajaxUrls.passkeys_admin_clear_nudge;
    const button = document.querySelector('.passkey-clear-nudge');
    button.click();
    await flush();

    expect(requests).toHaveLength(0);
    expect(button.disabled).toBe(false);
    expect(button.textContent).toBe('Clear nudge');
    expect(notifications[0].severity).toBe('error');
    expect(notifications[0].message).toContain('flush all TYPO3 caches');
  });
});
