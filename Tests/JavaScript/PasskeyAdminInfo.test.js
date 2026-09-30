/**
 * Unit tests for the SHIPPED PasskeyAdminInfo.js, the FormEngine element in a
 * be_users record: revoking one passkey, revoking all of them and resetting
 * the login lock. Each action is confirmed in a modal, POSTs through the
 * sudo-mode interceptor and updates the credential list it belongs to.
 *
 * The constructor waits for DocumentService.ready(), which the stub never
 * resolves, so each test hands the element to the instance and registers the
 * events itself.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { requests, respondWith, resetRequests } from '@typo3/core/ajax/ajax-request.js';
import { notifications } from '@typo3/backend/notification.js';
import { modals, press } from '@typo3/backend/modal.js';
import { sudoModeInterceptor } from '@typo3/backend/security/sudo-mode-interceptor.js';

const { default: PasskeyAdminInfo } = await import('@netresearch/nr-passkeys-be/PasskeyAdminInfo.js');

const MARKUP = `
  <fieldset>
    <span class="badge badge-success t3js-passkey-status-label" data-alternative-label="No passkeys">Passkeys active</span>
    <div id="passkey-admin-info">
      <ul class="t3js-passkey-credentials-list">
        <li id="passkey-credential-21">
          <span class="badge badge-success">Active</span>
          <button class="t3js-passkey-revoke-button" data-credential-uid="21"
            data-confirmation-title="Revoke?" data-confirmation-revoke-text="Yes, revoke">Revoke</button>
        </li>
        <li id="passkey-credential-22">
          <span class="badge badge-success">Active</span>
          <button class="t3js-passkey-revoke-button" data-credential-uid="22">Revoke</button>
        </li>
      </ul>
      <button class="t3js-passkey-revoke-all-button">Revoke all</button>
      <button class="t3js-passkey-unlock-button">Reset login lock</button>
    </div>
  </fieldset>
`;

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

let info;
let element;

function mount(options = { userId: 5, username: 'alice' }) {
  const parsed = new DOMParser().parseFromString(MARKUP, 'text/html');
  document.body.replaceChildren(...parsed.body.childNodes);
  element = document.getElementById('passkey-admin-info');
  info = new PasskeyAdminInfo('#passkey-admin-info', options);
  info.fullElement = element;
  info.registerEvents();
}

const item = (uid) => element.querySelector('#passkey-credential-' + uid);
const statusLabel = () => document.querySelector('.t3js-passkey-status-label');
const revokeAllButton = () => element.querySelector('.t3js-passkey-revoke-all-button');

beforeEach(() => {
  globalThis.TYPO3 = {
    lang: {},
    settings: {
      ajaxUrls: {
        passkeys_admin_remove: '/ajax/remove',
        passkeys_admin_revoke_all: '/ajax/revoke-all',
        passkeys_admin_unlock: '/ajax/unlock',
      },
    },
  };
  resetRequests();
  notifications.length = 0;
  modals.length = 0;
  mount();
});

describe('revoking one passkey', () => {
  it('asks for confirmation with the texts the button carries, and sends nothing on cancel', () => {
    item(21).querySelector('button').click();

    expect(modals).toHaveLength(1);
    expect(modals[0].title).toBe('Revoke?');
    expect(modals[0].buttons.map((button) => button.text)).toEqual(['Cancel', 'Yes, revoke']);

    press(modals[0], 'cancel');

    expect(modals[0].hidden).toBe(true);
    expect(requests).toHaveLength(0);
  });

  it('uses default texts when the button carries none', () => {
    item(22).querySelector('button').click();

    expect(modals[0].title).toBe('Revoke passkey');
    expect(modals[0].content).toBe('Are you sure you want to revoke this passkey?');
  });

  it('posts the user and credential through the sudo-mode interceptor', async () => {
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();

    expect(modals[0].hidden).toBe(true);
    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe('/ajax/remove');
    expect(requests[0].method).toBe('POST');
    expect(requests[0].body).toEqual({ beUserUid: 5, credentialUid: 21 });
    expect(requests[0].middlewares).toContain(sudoModeInterceptor);
    expect(info.request).toBeNull();
  });

  it('marks the item revoked but keeps the others and the overall status while one stays active', async () => {
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();

    expect(notifications).toEqual([{ severity: 'success', title: 'Passkey revoked', message: undefined }]);
    expect(item(21).dataset.passkeyStatus).toBe('revoked');
    expect(item(21).querySelector('.badge').className).toBe('badge badge-danger');
    expect(item(21).querySelector('.badge').textContent).toBe('Revoked');
    expect(item(21).querySelector('.t3js-passkey-revoke-button')).toBeNull();
    expect(item(22).dataset.passkeyStatus).toBeUndefined();
    expect(revokeAllButton().hasAttribute('disabled')).toBe(false);
    expect(statusLabel().classList.contains('badge-success')).toBe(true);
  });

  it('disables "revoke all" and flips the status label once the last active passkey is revoked', async () => {
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();
    item(22).querySelector('button').click();
    press(modals[1], 'revoke');
    await flush();

    expect(revokeAllButton().hasAttribute('disabled')).toBe(true);
    expect(revokeAllButton().classList.contains('disabled')).toBe(true);
    expect(statusLabel().innerText).toBe('No passkeys');
    expect(statusLabel().classList.contains('badge-danger')).toBe(true);
  });

  it('uses the labels passed in the options', async () => {
    mount({ userId: 5, labels: { revoked: 'Passkey widerrufen', badgeRevoked: 'Widerrufen' } });
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();

    expect(notifications[0].title).toBe('Passkey widerrufen');
    expect(item(21).querySelector('.badge').textContent).toBe('Widerrufen');
  });

  it('reports a refusal and leaves the item active', async () => {
    respondWith(() => ({ status: 'error', error: 'Credential not found' }));
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();

    expect(notifications).toEqual([
      { severity: 'error', title: 'Failed to revoke passkey', message: 'Credential not found' },
    ]);
    expect(item(21).dataset.passkeyStatus).toBeUndefined();
  });

  it('reports a failed request', async () => {
    respondWith(() => {
      throw new Error('network down');
    });
    item(21).querySelector('button').click();
    press(modals[0], 'revoke');
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: 'Request failed', message: undefined }]);
    expect(info.request).toBeNull();
  });

  it('ignores a revoke for an item that is no longer in the list', () => {
    expect(() => info.markItemAsRevoked(99)).not.toThrow();
  });
});

describe('revoking all passkeys', () => {
  it('posts the user, marks every item revoked and reports the count', async () => {
    respondWith(() => ({ status: 'ok', revokedCount: 2 }));
    revokeAllButton().click();

    expect(modals[0].title).toBe('Revoke all passkeys');
    press(modals[0], 'revokeAll');
    await flush();

    expect(requests[0].url).toBe('/ajax/revoke-all');
    expect(requests[0].body).toEqual({ beUserUid: 5 });
    expect(requests[0].middlewares).toContain(sudoModeInterceptor);
    expect(notifications).toEqual([{ severity: 'success', title: 'All passkeys revoked (2)', message: undefined }]);
    expect(item(21).dataset.passkeyStatus).toBe('revoked');
    expect(item(22).dataset.passkeyStatus).toBe('revoked');
    expect(element.querySelectorAll('.t3js-passkey-revoke-button')).toHaveLength(0);
    expect(revokeAllButton().hasAttribute('disabled')).toBe(true);
    expect(statusLabel().innerText).toBe('No passkeys');
  });

  it('sends nothing when cancelled', () => {
    revokeAllButton().click();
    press(modals[0], 'cancel');

    expect(requests).toHaveLength(0);
  });

  it('reports a refusal', async () => {
    respondWith(() => ({ status: 'error', error: 'Not allowed' }));
    revokeAllButton().click();
    press(modals[0], 'revokeAll');
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: 'Failed to revoke passkeys', message: 'Not allowed' }]);
    expect(item(21).dataset.passkeyStatus).toBeUndefined();
  });

  it('reports a failed request', async () => {
    respondWith(() => {
      throw new Error('network down');
    });
    revokeAllButton().click();
    press(modals[0], 'revokeAll');
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: 'Request failed', message: undefined }]);
  });

  it('does nothing to the list when the element has none', () => {
    element.querySelector('.t3js-passkey-credentials-list').remove();

    expect(() => info.markAllItemsAsRevoked()).not.toThrow();
    expect(revokeAllButton().hasAttribute('disabled')).toBe(false);
  });
});

describe('resetting the login lock', () => {
  it('posts the user id and username and reports success', async () => {
    element.querySelector('.t3js-passkey-unlock-button').click();

    expect(modals[0].title).toBe('Reset login lock');
    press(modals[0], 'unlock');
    await flush();

    expect(requests[0].url).toBe('/ajax/unlock');
    expect(requests[0].body).toEqual({ beUserUid: 5, username: 'alice' });
    expect(requests[0].middlewares).toContain(sudoModeInterceptor);
    expect(notifications).toEqual([{ severity: 'success', title: 'Login lock reset', message: undefined }]);
  });

  it('sends nothing when cancelled', () => {
    element.querySelector('.t3js-passkey-unlock-button').click();
    press(modals[0], 'cancel');

    expect(requests).toHaveLength(0);
  });

  it('reports a refusal', async () => {
    respondWith(() => ({ status: 'error' }));
    element.querySelector('.t3js-passkey-unlock-button').click();
    press(modals[0], 'unlock');
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: 'Failed to reset login lock', message: '' }]);
  });

  it('reports a failed request', async () => {
    respondWith(() => {
      throw new Error('network down');
    });
    element.querySelector('.t3js-passkey-unlock-button').click();
    press(modals[0], 'unlock');
    await flush();

    expect(notifications).toEqual([{ severity: 'error', title: 'Request failed', message: undefined }]);
  });
});

describe('overlapping requests', () => {
  it('aborts the request still in flight before sending the next one', async () => {
    info.sendUnlockRequest();
    const first = requests[0].instance;
    info.sendRevokeAllRequest();

    expect(first.aborted).toBe(true);
    expect(requests[1].instance.aborted).toBe(false);
    expect(requests).toHaveLength(2);
    await flush();
  });
});
