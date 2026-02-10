/**
 * Passkey Login - WebAuthn authentication for TYPO3 Backend
 *
 * Handles the passkey login flow:
 * 1. User enters username
 * 2. Click "Continue with Passkey"
 * 3. Fetch assertion options from server
 * 4. Call navigator.credentials.get()
 * 5. Submit result via the standard TYPO3 login form
 */
(function () {
  'use strict';

  function init() {
    const container = document.getElementById('passkey-login-container');
    if (!container) return;

  const optionsUrl = container.dataset.optionsUrl;
  const loginBtn = document.getElementById('passkey-login-btn');
  const btnText = document.getElementById('passkey-btn-text');
  const btnLoading = document.getElementById('passkey-btn-loading');
  const errorEl = document.getElementById('passkey-error');
  const usernameInput = document.getElementById('passkey-username');
  const assertionField = document.getElementById('passkey-assertion');
  const challengeTokenField = document.getElementById('passkey-challenge-token');

  // Check WebAuthn support
  if (!window.PublicKeyCredential) {
    showError('Your browser does not support Passkeys (WebAuthn).');
    if (loginBtn) loginBtn.disabled = true;
    return;
  }

  // Check secure context
  if (!window.isSecureContext) {
    showError('Passkeys require a secure connection (HTTPS).');
    if (loginBtn) loginBtn.disabled = true;
    return;
  }

  if (loginBtn) {
    loginBtn.addEventListener('click', handlePasskeyLogin);
  }

  // Allow Enter key in username field
  if (usernameInput) {
    usernameInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        handlePasskeyLogin();
      }
    });
  }

  async function handlePasskeyLogin() {
    hideError();
    const username = usernameInput ? usernameInput.value.trim() : '';

    if (!username) {
      showError('Please enter your username.');
      if (usernameInput) usernameInput.focus();
      return;
    }

    setLoading(true);

    try {
      // Step 1: Fetch assertion options from server
      const optionsResponse = await fetch(optionsUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username: username }),
      });

      if (!optionsResponse.ok) {
        const data = await optionsResponse.json().catch(() => ({}));
        if (optionsResponse.status === 429) {
          showError('Too many attempts. Please try again later.');
        } else {
          showError(data.error || 'Authentication failed. Please try again.');
        }
        setLoading(false);
        return;
      }

      const optionsData = await optionsResponse.json();
      const options = optionsData.options;
      const challengeToken = optionsData.challengeToken;

      // Step 2: Prepare options for navigator.credentials.get()
      const publicKeyOptions = {
        challenge: base64urlToBuffer(options.challenge),
        rpId: options.rpId,
        timeout: options.timeout || 60000,
        userVerification: options.userVerification || 'required',
      };

      if (options.allowCredentials && options.allowCredentials.length > 0) {
        publicKeyOptions.allowCredentials = options.allowCredentials.map(function (cred) {
          return {
            type: cred.type,
            id: base64urlToBuffer(cred.id),
            transports: cred.transports || [],
          };
        });
      }

      // Step 3: Call WebAuthn API
      const assertion = await navigator.credentials.get({
        publicKey: publicKeyOptions,
      });

      // Step 4: Encode the response
      const credentialResponse = {
        id: bufferToBase64url(assertion.rawId),
        rawId: bufferToBase64(assertion.rawId),
        type: assertion.type,
        response: {
          clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
          authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
          signature: bufferToBase64url(assertion.response.signature),
          userHandle: assertion.response.userHandle
            ? bufferToBase64url(assertion.response.userHandle)
            : null,
        },
      };

      // Step 5: Submit via TYPO3 login form
      if (assertionField) {
        assertionField.value = JSON.stringify(credentialResponse);
      }
      if (challengeTokenField) {
        challengeTokenField.value = challengeToken;
      }

      // Also set the username in the standard TYPO3 login form
      const typo3UsernameField = document.querySelector('input[name="username"]');
      if (typo3UsernameField) {
        typo3UsernameField.value = username;
      }

      // Submit the login form
      const loginForm = document.querySelector('form[name="loginform"], form.typo3-login-form, #typo3-login-form');
      if (loginForm) {
        loginForm.submit();
      } else {
        showError('Could not find login form. Please try again.');
        setLoading(false);
      }
    } catch (err) {
      setLoading(false);

      if (err.name === 'NotAllowedError') {
        showError('Authentication was cancelled.');
      } else if (err.name === 'SecurityError') {
        showError('Security error. Please check your connection.');
      } else if (err.name === 'AbortError') {
        showError('Authentication was cancelled.');
      } else {
        showError('Authentication failed. Please try again.');
        console.error('Passkey login error:', err);
      }
    }
  }

  function setLoading(loading) {
    if (loginBtn) loginBtn.disabled = loading;
    if (btnText) btnText.classList.toggle('d-none', loading);
    if (btnLoading) btnLoading.classList.toggle('d-none', !loading);
  }

  function showError(message) {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.classList.remove('d-none');
    }
  }

  function hideError() {
    if (errorEl) {
      errorEl.classList.add('d-none');
      errorEl.textContent = '';
    }
  }

  // Base64URL encoding/decoding utilities
  function base64urlToBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const padLen = (4 - (base64.length % 4)) % 4;
    const padded = base64 + '='.repeat(padLen);
    const binary = atob(padded);
    const buffer = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      buffer[i] = binary.charCodeAt(i);
    }
    return buffer.buffer;
  }

  function bufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }
  } // end init()

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
