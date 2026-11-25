/*
 * Copyright (c) 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * "Confirm with passkey" in TYPO3's sudo-mode dialog.
 *
 * TYPO3 asks for the password before sensitive operations (changing a
 * password, creating an admin, group permissions, MFA). The dialog is the
 * core element <typo3-backend-security-sudo-mode-form>; this module adds a
 * passkey button above its password field and leaves the field in place.
 *
 * Flow:
 * 1. POST passkeys_sudo_mode_options: assertion options for the current user
 *    (none if the user has no passkey: then nothing is added).
 * 2. navigator.credentials.get() with user verification.
 * 3. POST passkeys_sudo_mode_verify: the server checks the assertion and
 *    returns a single-use token bound to user, session and claim.
 * 4. The token goes into the core form's password field and the core form is
 *    submitted. The core verify endpoint accepts it via SudoModeVerifyEvent,
 *    so redirects, AJAX replays and the modal behave exactly as with a
 *    password.
 *
 * If anything here fails, the password field still works as before.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { base64urlToBuffer, bufferToBase64url, bufferToBase64 } from '@netresearch/nr-passkeys-be/Util/Base64.js';

const FORM_ELEMENT = 'typo3-backend-security-sudo-mode-form';
const MARKER = 'nrPasskeySudo';
// A challenge older than this is fetched again before the ceremony starts.
const OPTIONS_MAX_AGE_MS = 60000;

function translate(key, fallback) {
  return window.TYPO3?.lang?.[key] || fallback;
}

function ajaxUrl(name) {
  return window.TYPO3?.settings?.ajaxUrls?.[name];
}

export function claimIdFromVerifyUri(verifyActionUri) {
  if (!verifyActionUri) {
    return '';
  }
  try {
    return new URL(verifyActionUri, window.location.origin).searchParams.get('claim') || '';
  } catch {
    return '';
  }
}

export function buildPublicKeyOptions(options) {
  const publicKeyOptions = {
    challenge: base64urlToBuffer(options.challenge),
    rpId: options.rpId,
    timeout: options.timeout || 60000,
    userVerification: options.userVerification || 'required',
  };
  if (options.allowCredentials && options.allowCredentials.length > 0) {
    publicKeyOptions.allowCredentials = options.allowCredentials.map((cred) => ({
      type: cred.type,
      id: base64urlToBuffer(cred.id),
      transports: cred.transports || [],
    }));
  }
  return publicKeyOptions;
}

export function encodeAssertion(assertion) {
  return {
    id: bufferToBase64url(assertion.rawId),
    rawId: bufferToBase64(assertion.rawId),
    type: assertion.type,
    response: {
      clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
      authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
      signature: bufferToBase64url(assertion.response.signature),
      userHandle: assertion.response.userHandle ? bufferToBase64url(assertion.response.userHandle) : null,
    },
  };
}

function errorMessage(err) {
  if (err?.name === 'NotAllowedError') {
    return translate('js.sudo.error.notAllowed', 'The passkey prompt was cancelled or no matching passkey was found.');
  }
  return translate('js.sudo.error.generic', 'Confirmation with the passkey failed. You can use your password instead.');
}

export class SudoModePasskey {
  constructor(formElement, credentialsApi) {
    this.formElement = formElement;
    this.credentialsApi = credentialsApi;
    this.options = null;
    this.optionsFetchedAt = 0;
    this.busy = false;
    this.container = null;
  }

  async initialize() {
    if (!window.PublicKeyCredential || !this.credentialsApi || this.formElement.hasFatalError) {
      return;
    }
    if (!ajaxUrl('passkeys_sudo_mode_options') || !ajaxUrl('passkeys_sudo_mode_verify')) {
      return;
    }
    if (claimIdFromVerifyUri(this.formElement.verifyActionUri) === '') {
      return;
    }
    let data;
    try {
      data = await this.fetchOptions();
    } catch {
      return;
    }
    if (data?.available !== true) {
      return;
    }
    this.render();
    this.sync();
  }

  async fetchOptions() {
    const response = await new AjaxRequest(ajaxUrl('passkeys_sudo_mode_options')).post({});
    const data = await response.resolve();
    if (data?.available === true) {
      this.options = data;
      this.optionsFetchedAt = Date.now();
    }
    return data;
  }

  render() {
    const form = this.formElement.querySelector('form');
    if (!form) {
      return;
    }
    const container = document.createElement('div');
    container.className = 'nr-passkeys-sudo mb-3';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-primary w-100';
    button.textContent = translate('js.sudo.button', 'Confirm with passkey');
    button.addEventListener('click', () => this.confirm(button));

    const error = document.createElement('div');
    error.className = 'alert alert-danger mt-2 d-none';
    error.setAttribute('role', 'alert');

    const divider = document.createElement('p');
    divider.className = 'text-body-secondary text-center small mt-3 mb-0';
    divider.textContent = translate('js.sudo.or', 'or confirm with your password');

    container.append(button, error, divider);
    form.parentNode.insertBefore(container, form);
    this.container = container;
    this.button = button;
    this.error = error;
  }

  /** Hidden while the dialog is in install tool password mode. */
  sync() {
    if (this.container) {
      this.container.classList.toggle('d-none', this.formElement.useInstallToolPassword === true);
    }
  }

  showError(message) {
    this.error.textContent = message;
    this.error.classList.remove('d-none');
  }

  async confirm(button) {
    if (this.busy) {
      return;
    }
    this.busy = true;
    button.disabled = true;
    this.error.classList.add('d-none');
    try {
      if (this.options === null || Date.now() - this.optionsFetchedAt > OPTIONS_MAX_AGE_MS) {
        await this.fetchOptions();
      }
      const options = this.options;
      // Each challenge is single-use, whatever happens next.
      this.options = null;
      if (!options) {
        throw new Error('no options');
      }

      const assertion = await this.credentialsApi.get({ publicKey: buildPublicKeyOptions(options.options) });
      const response = await new AjaxRequest(ajaxUrl('passkeys_sudo_mode_verify')).post(
        {
          claim: claimIdFromVerifyUri(this.formElement.verifyActionUri),
          challengeToken: options.challengeToken,
          credential: encodeAssertion(assertion),
        },
        { headers: { 'Content-Type': 'application/json' } },
      );
      const data = await response.resolve();
      if (!data || typeof data.token !== 'string') {
        throw new Error('no token');
      }

      const form = this.formElement.querySelector('form');
      const password = this.formElement.querySelector('input[type=password]');
      if (!form || !password) {
        throw new Error('form not found');
      }
      password.value = data.token;
      form.requestSubmit();
    } catch (err) {
      this.showError(errorMessage(err));
    } finally {
      this.busy = false;
      button.disabled = false;
    }
  }
}

function credentialsApi() {
  try {
    if (window.top && window.top !== window && window.top.navigator?.credentials) {
      return window.top.navigator.credentials;
    }
  } catch {
    // Cross-origin parent: use our own window.
  }
  return navigator.credentials;
}

/**
 * The form element renders into its own light DOM, but the modal around it
 * may not be in the light DOM of the page. Hooking the element's Lit update
 * finds every instance, wherever it is attached.
 */
export function attach(ElementClass) {
  if (!ElementClass || ElementClass.prototype[MARKER]) {
    return;
  }
  const original = ElementClass.prototype.updated;
  ElementClass.prototype[MARKER] = true;
  ElementClass.prototype.updated = function (changed) {
    if (typeof original === 'function') {
      original.call(this, changed);
    }
    // Whatever goes wrong here must not break the core dialog: the password
    // field has to keep working.
    try {
      if (!this[MARKER + 'Instance']) {
        this[MARKER + 'Instance'] = new SudoModePasskey(this, credentialsApi());
        this[MARKER + 'Instance'].initialize().catch(() => {});
      } else {
        this[MARKER + 'Instance'].sync();
      }
    } catch {
      // Password only.
    }
  };
}

if (window.customElements) {
  window.customElements
    .whenDefined(FORM_ELEMENT)
    .then(() => attach(window.customElements.get(FORM_ELEMENT)))
    .catch(() => {
      // Without the hook the dialog keeps its password field only.
    });
}
