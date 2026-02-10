/**
 * Passkey Login - WebAuthn authentication for TYPO3 Backend
 *
 * Injects a passkey login button into the standard TYPO3 login form.
 * Configuration is provided via window.NrPasskeysBeConfig (set by the
 * InjectPasskeyLoginFields event listener).
 *
 * Flow:
 * 1. Reads username from the standard #t3-username field
 * 2. Click "Sign in with Passkey"
 * 3. Fetch assertion options from server
 * 4. Call navigator.credentials.get()
 * 5. Submit result via the standard TYPO3 login form (#typo3-login-form)
 */
(function () {
  'use strict';

  function init() {
    var config = window.NrPasskeysBeConfig;
    if (!config || !config.loginOptionsUrl) return;

    var loginForm = document.getElementById('typo3-login-form');
    if (!loginForm) return;

    // Build and inject passkey UI into the login form
    var container = buildPasskeyUI();
    var submitSection = document.getElementById('t3-login-submit-section');
    if (submitSection) {
      submitSection.parentNode.insertBefore(container, submitSection);
    } else {
      loginForm.appendChild(container);
    }

    var optionsUrl = config.loginOptionsUrl;
    var usernameInput = document.getElementById('t3-username');
    var loginBtn = document.getElementById('passkey-login-btn');
    var btnText = document.getElementById('passkey-btn-text');
    var btnLoading = document.getElementById('passkey-btn-loading');
    var errorEl = document.getElementById('passkey-error');
    var assertionField = document.getElementById('passkey-assertion');
    var challengeTokenField = document.getElementById('passkey-challenge-token');

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

    // Detect failed passkey login from previous attempt
    checkForFailedPasskeyLogin();

    if (loginBtn) {
      loginBtn.addEventListener('click', handlePasskeyLogin);
    }

    function checkForFailedPasskeyLogin() {
      try {
        if (sessionStorage.getItem('nr_passkey_attempt')) {
          sessionStorage.removeItem('nr_passkey_attempt');
          // Still on the login page after a passkey submission = auth failed.
          // TYPO3 does a POST-Redirect-GET after failed login, so the generic
          // error div (#t3-login-error) may not be present on the redirected page.
          showError('Passkey authentication failed. Your passkey was not accepted. Please try again or sign in with your password.');
          // Hide generic TYPO3 error if it exists
          var typo3Error = document.getElementById('t3-login-error');
          if (typo3Error) {
            typo3Error.style.display = 'none';
          }
        }
      } catch (e) {
        // sessionStorage may be unavailable
      }
    }

    async function handlePasskeyLogin() {
      hideError();
      var username = usernameInput ? usernameInput.value.trim() : '';

      if (!username && !config.discoverableEnabled) {
        showError('Please enter your username.');
        if (usernameInput) usernameInput.focus();
        return;
      }

      setLoading(true);

      try {
        // Step 1: Fetch assertion options from server
        var optionsResponse = await fetch(optionsUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ username: username }),
        });

        if (!optionsResponse.ok) {
          var data = await optionsResponse.json().catch(function () { return {}; });
          if (optionsResponse.status === 429) {
            showError('Too many attempts. Please try again later.');
          } else {
            showError(data.error || 'Authentication failed. Please try again.');
          }
          setLoading(false);
          return;
        }

        var optionsData = await optionsResponse.json();
        var options = optionsData.options;
        var challengeToken = optionsData.challengeToken;

        // Step 2: Prepare options for navigator.credentials.get()
        var publicKeyOptions = {
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
        var assertion = await navigator.credentials.get({
          publicKey: publicKeyOptions,
        });

        // Step 4: Encode the response
        var credentialResponse = {
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
        // Pack assertion + challenge token into the userident field.
        // TYPO3 auth services receive login data via $this->login['uident']
        // which maps to the userident POST field. $GLOBALS['TYPO3_REQUEST']
        // is not available during the auth service chain, so custom POST
        // fields (passkey_assertion etc.) are inaccessible. Using userident
        // is the standard TYPO3 way to pass authentication credentials.
        var passkeyPayload = JSON.stringify({
          _type: 'passkey',
          assertion: credentialResponse,
          challengeToken: challengeToken,
        });

        var useridentField = document.querySelector('.t3js-login-userident-field');
        if (useridentField) {
          useridentField.value = passkeyPayload;
        }

        // Also keep hidden fields populated for any middleware/hook inspection
        if (assertionField) {
          assertionField.value = JSON.stringify(credentialResponse);
        }
        if (challengeTokenField) {
          challengeTokenField.value = challengeToken;
        }

        // Ensure the username field has the value
        if (usernameInput) {
          usernameInput.value = username;
        }

        // Flag this as a passkey attempt so we can show a specific error
        // if the server-side verification fails and the page reloads
        try { sessionStorage.setItem('nr_passkey_attempt', '1'); } catch (e) { /* ignore */ }

        loginForm.submit();
      } catch (err) {
        setLoading(false);

        if (err.name === 'NotAllowedError') {
          showError('Authentication was cancelled or no passkey found for this site. Have you registered a passkey?');
        } else if (err.name === 'SecurityError') {
          showError('Security error. Please check your connection.');
        } else if (err.name === 'AbortError') {
          showError('Authentication was cancelled.');
        } else {
          showError('Authentication failed: ' + (err.message || 'Please try again.'));
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
  }

  function buildPasskeyUI() {
    var container = document.createElement('div');
    container.id = 'passkey-login-container';
    container.className = 'passkey-login';

    container.innerHTML =
      '<div class="form-group mb-2">' +
        '<div class="d-grid">' +
          '<button type="button" id="passkey-login-btn" class="btn btn-default btn-block w-100">' +
            '<span class="passkey-icon me-2">&#128274;</span>' +
            '<span id="passkey-btn-text">Sign in with Passkey</span>' +
            '<span id="passkey-btn-loading" class="d-none">' +
              '<span class="spinner-border spinner-border-sm me-2" role="status"></span>' +
              'Authenticating...' +
            '</span>' +
          '</button>' +
        '</div>' +
      '</div>' +
      '<div id="passkey-error" class="alert alert-danger d-none mb-2" role="alert"></div>' +
      '<input type="hidden" name="passkey_assertion" id="passkey-assertion" value="" />' +
      '<input type="hidden" name="passkey_challenge_token" id="passkey-challenge-token" value="" />';

    return container;
  }

  // Base64URL encoding/decoding utilities
  function base64urlToBuffer(base64url) {
    var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    var padLen = (4 - (base64.length % 4)) % 4;
    var padded = base64 + '='.repeat(padLen);
    var binary = atob(padded);
    var buffer = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      buffer[i] = binary.charCodeAt(i);
    }
    return buffer.buffer;
  }

  function bufferToBase64url(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function bufferToBase64(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
