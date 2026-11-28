/*
 * Copyright (c) 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { requests, respondWith, resetRequests } from '@typo3/core/ajax/ajax-request.js';
import {
  attach,
  claimIdFromVerifyUri,
  SudoModePasskey,
} from '../../Resources/Public/JavaScript/SudoModePasskey.js';

/** Answers each request from (url, body), as the server would. */
function answer(fn) {
  respondWith((request) => fn(request.url, request.body));
}

const VERIFY_URI = '/typo3/ajax/sudo-mode/verify?claim=claim-1&hash=abc';

function sudoForm({ installTool = false, fatal = false } = {}) {
  const element = document.createElement('div');
  element.verifyActionUri = VERIFY_URI;
  element.useInstallToolPassword = installTool;
  element.hasFatalError = fatal;
  element.innerHTML = '<div><p>Explanation</p><form id="verify-sudo-mode"><input type="password" id="password" required></form></div>';
  document.body.append(element);
  const form = element.querySelector('form');
  form.requestSubmit = vi.fn();
  return { element, form, password: element.querySelector('input') };
}

const OPTIONS = {
  available: true,
  challengeToken: 'ct',
  options: { challenge: 'AAAA', rpId: 'example.com', allowCredentials: [{ type: 'public-key', id: 'AQID' }] },
};

function fakeAssertion() {
  const bytes = (n) => new Uint8Array([n]).buffer;
  return {
    rawId: bytes(1),
    type: 'public-key',
    response: { clientDataJSON: bytes(2), authenticatorData: bytes(3), signature: bytes(4), userHandle: null },
  };
}

beforeEach(() => {
  document.body.innerHTML = '';
  resetRequests();
  window.PublicKeyCredential = function () {};
  globalThis.TYPO3 = {
    lang: {},
    settings: { ajaxUrls: { passkeys_sudo_mode_options: '/options', passkeys_sudo_mode_verify: '/verify' } },
  };
});

describe('claimIdFromVerifyUri', () => {
  it('reads the claim from the core verify URI', () => {
    expect(claimIdFromVerifyUri(VERIFY_URI)).toBe('claim-1');
  });
  it('is empty without a claim', () => {
    expect(claimIdFromVerifyUri('/typo3/ajax/sudo-mode/verify')).toBe('');
    expect(claimIdFromVerifyUri(undefined)).toBe('');
  });
});

describe('SudoModePasskey', () => {
  it('adds nothing for a user without a passkey', async () => {
    answer(async () => ({ available: false }));
    const { element } = sudoForm();

    await new SudoModePasskey(element, { get: vi.fn() }).initialize();

    expect(element.querySelector('.nr-passkeys-sudo')).toBeNull();
  });

  it('adds the button above the password form and keeps the field', async () => {
    answer(async () => OPTIONS);
    const { element, password } = sudoForm();

    await new SudoModePasskey(element, { get: vi.fn() }).initialize();

    const container = element.querySelector('.nr-passkeys-sudo');
    expect(container).not.toBeNull();
    expect(container.nextElementSibling.tagName).toBe('FORM');
    expect(password.isConnected).toBe(true);
  });

  it('asks nothing when the dialog already failed', async () => {
    const handler = vi.fn(async () => OPTIONS);
    answer(handler);
    const { element } = sudoForm({ fatal: true });

    await new SudoModePasskey(element, { get: vi.fn() }).initialize();

    expect(handler).not.toHaveBeenCalled();
  });

  it('hides the button in install tool password mode', async () => {
    answer(async () => OPTIONS);
    const { element } = sudoForm({ installTool: true });

    await new SudoModePasskey(element, { get: vi.fn() }).initialize();

    expect(element.querySelector('.nr-passkeys-sudo').classList.contains('d-none')).toBe(true);
  });

  it('submits the server token through the core form', async () => {
    answer(async (url) => (url === '/options' ? OPTIONS : { token: 'nrpk-sudo:t' }));
    const credentials = { get: vi.fn(async () => fakeAssertion()) };
    const { element, form, password } = sudoForm();
    const subject = new SudoModePasskey(element, credentials);
    await subject.initialize();

    await subject.confirm(subject.button);

    const verify = requests.find((request) => request.url === '/verify');
    expect(verify.body.claim).toBe('claim-1');
    expect(verify.body.challengeToken).toBe('ct');
    expect(credentials.get.mock.calls[0][0].publicKey.userVerification).toBe('required');
    expect(password.value).toBe('nrpk-sudo:t');
    expect(form.requestSubmit).toHaveBeenCalledOnce();
  });

  it('shows an error and leaves the password field alone when the passkey prompt is cancelled', async () => {
    answer(async () => OPTIONS);
    const err = new Error('cancelled');
    err.name = 'NotAllowedError';
    const { element, form, password } = sudoForm();
    const subject = new SudoModePasskey(element, { get: vi.fn(async () => { throw err; }) });
    await subject.initialize();

    await subject.confirm(subject.button);

    expect(subject.error.classList.contains('d-none')).toBe(false);
    expect(password.value).toBe('');
    expect(form.requestSubmit).not.toHaveBeenCalled();
    expect(subject.button.disabled).toBe(false);
  });

  it('never reuses a challenge after an attempt', async () => {
    let optionCalls = 0;
    answer(async (url) => {
      if (url === '/options') {
        optionCalls++;
        return OPTIONS;
      }
      throw new Error('403');
    });
    const { element } = sudoForm();
    const subject = new SudoModePasskey(element, { get: vi.fn(async () => fakeAssertion()) });
    await subject.initialize();

    await subject.confirm(subject.button);
    await subject.confirm(subject.button);

    expect(optionCalls).toBe(2);
  });
});

describe('attach', () => {
  it('hooks every instance of the element once', async () => {
    answer(async () => ({ available: false }));
    const calls = [];
    class FakeForm {
      updated(changed) {
        calls.push(changed);
      }
    }
    attach(FakeForm);
    attach(FakeForm);

    const instance = new FakeForm();
    instance.verifyActionUri = VERIFY_URI;
    instance.querySelector = () => null;
    instance.updated('a');
    instance.updated('b');

    expect(calls).toEqual(['a', 'b']);
    expect(instance.nrPasskeySudoInstance).toBeInstanceOf(SudoModePasskey);
  });
});

describe('attach when something breaks', () => {
  it('keeps the core update running', () => {
    const calls = [];
    class BrokenForm {
      updated(changed) {
        calls.push(changed);
      }
      get verifyActionUri() {
        throw new Error('boom');
      }
    }
    attach(BrokenForm);

    const instance = new BrokenForm();
    expect(() => instance.updated('a')).not.toThrow();
    expect(calls).toEqual(['a']);
  });
});
