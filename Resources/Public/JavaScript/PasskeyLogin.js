/**
 * Passkey Login - WebAuthn authentication for TYPO3 Backend
 *
 * Injects a passkey login button into the standard TYPO3 login form.
 * Configuration is provided via window.NrPasskeysBeConfig (set by the
 * InjectPasskeyLoginFields event listener).
 *
 * Flow:
 * 1. Reads username from the standard #t3-username field
 * 2. Click "Sign in with a passkey"
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

    // Build and inject passkey UI below the login button with an "or" divider
    var container = buildPasskeyUI();
    var submitSection = document.getElementById('t3-login-submit-section');
    if (submitSection) {
      submitSection.parentNode.insertBefore(container, submitSection.nextSibling);
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

  // Material Symbols "passkey" icon (Apache 2.0, google/material-design-icons)
  var PASSKEY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" height="20" ' +
    'viewBox="0 -960 960 960" width="20" fill="currentColor" ' +
    'style="vertical-align:middle">' +
    '<path d="M120-160v-112q0-34 17.5-62.5T184-378q62-31 126-46.5T440-440' +
    'q20 0 40 1.5t40 4.5q-4 58 21 109.5t73 84.5v80H120ZM760-40l-60-60v-186' +
    'q-44-13-72-49.5T600-420q0-58 41-99t99-41q58 0 99 41t41 99q0 45-25.5 ' +
    '80T790-290l50 50-60 60 60 60-80 80ZM440-480q-66 0-113-47t-47-113q0-66' +
    ' 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47Zm300 80q17 0 ' +
    '28.5-11.5T780-440q0-17-11.5-28.5T740-480q-17 0-28.5 11.5T700-440q0 ' +
    '17 11.5 28.5T740-400Z"/></svg>';

  function buildPasskeyUI() {
    // All content is static/hardcoded — no user input in this markup
    var divider = document.createElement('div');
    divider.className = 'passkey-divider mb-2 mt-2';
    divider.setAttribute('style', 'display:flex;align-items:center;gap:8px');
    var hrLeft = document.createElement('hr');
    hrLeft.setAttribute('style', 'flex:1;border:none;border-top:1px solid #ccc;margin:0');
    var orLabel = document.createElement('span');
    orLabel.setAttribute('style', 'color:#999;font-size:12px;text-transform:uppercase;letter-spacing:1px');
    orLabel.textContent = 'or';
    var hrRight = document.createElement('hr');
    hrRight.setAttribute('style', 'flex:1;border:none;border-top:1px solid #ccc;margin:0');
    divider.appendChild(hrLeft);
    divider.appendChild(orLabel);
    divider.appendChild(hrRight);

    var container = document.createElement('div');
    container.id = 'passkey-login-container';
    container.className = 'passkey-login';
    container.appendChild(divider);

    var formGroup = document.createElement('div');
    formGroup.className = 'form-group mb-2';
    var grid = document.createElement('div');
    grid.className = 'd-grid';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'passkey-login-btn';
    btn.className = 'btn btn-default btn-block w-100';

    // Icon uses static SVG from Material Symbols (Apache 2.0, no user input)
    var iconSpan = document.createElement('span');
    iconSpan.className = 'passkey-icon me-2';
    iconSpan.innerHTML = PASSKEY_ICON; // eslint-disable-line -- static SVG constant
    var textSpan = document.createElement('span');
    textSpan.id = 'passkey-btn-text';
    textSpan.textContent = 'Sign in with a passkey';
    var loadingSpan = document.createElement('span');
    loadingSpan.id = 'passkey-btn-loading';
    loadingSpan.className = 'd-none';
    var spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('role', 'status');
    loadingSpan.appendChild(spinner);
    loadingSpan.appendChild(document.createTextNode('Authenticating\u2026'));

    btn.appendChild(iconSpan);
    btn.appendChild(textSpan);
    btn.appendChild(loadingSpan);
    grid.appendChild(btn);
    formGroup.appendChild(grid);
    container.appendChild(formGroup);

    var errorDiv = document.createElement('div');
    errorDiv.id = 'passkey-error';
    errorDiv.className = 'alert alert-danger d-none mb-2';
    errorDiv.setAttribute('role', 'alert');
    container.appendChild(errorDiv);

    var assertionInput = document.createElement('input');
    assertionInput.type = 'hidden';
    assertionInput.name = 'passkey_assertion';
    assertionInput.id = 'passkey-assertion';
    assertionInput.value = '';
    container.appendChild(assertionInput);

    var tokenInput = document.createElement('input');
    tokenInput.type = 'hidden';
    tokenInput.name = 'passkey_challenge_token';
    tokenInput.id = 'passkey-challenge-token';
    tokenInput.value = '';
    container.appendChild(tokenInput);

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
