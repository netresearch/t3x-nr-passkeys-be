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
import { base64urlToBuffer, bufferToBase64url, bufferToBase64 } from '@netresearch/nr-passkeys-be/Util/Base64.js';

// Material Symbols "passkey" icon (Apache 2.0, google/material-design-icons)
const PASSKEY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" height="20" ' +
  'viewBox="0 -960 960 960" width="20" fill="currentColor" ' +
  'style="vertical-align:middle">' +
  '<path d="M120-160v-112q0-34 17.5-62.5T184-378q62-31 126-46.5T440-440' +
  'q20 0 40 1.5t40 4.5q-4 58 21 109.5t73 84.5v80H120ZM760-40l-60-60v-186' +
  'q-44-13-72-49.5T600-420q0-58 41-99t99-41q58 0 99 41t41 99q0 45-25.5 ' +
  '80T790-290l50 50-60 60 60 60-80 80ZM440-480q-66 0-113-47t-47-113q0-66' +
  ' 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47Zm300 80q17 0 ' +
  '28.5-11.5T780-440q0-17-11.5-28.5T740-480q-17 0-28.5 11.5T700-440q0 ' +
  '17 11.5 28.5T740-400Z"/></svg>';

function init() {
  const config = window.NrPasskeysBeConfig;
  if (!config || !config.loginOptionsUrl) return;

  // Server-translated UI labels (with English fallbacks if injection is absent).
  const L = config.labels || {};

  const loginForm = document.getElementById('typo3-login-form');
  if (!loginForm) return;

  // Build and inject passkey UI below the login button with an "or" divider
  const container = buildPasskeyUI(L);
  const submitSection = document.getElementById('t3-login-submit-section');
  if (submitSection) {
    submitSection.parentNode.insertBefore(container, submitSection.nextSibling);
  } else {
    loginForm.appendChild(container);
  }

  const optionsUrl = config.loginOptionsUrl;
  const usernameInput = document.getElementById('t3-username');
  const loginBtn = document.getElementById('passkey-login-btn');
  const btnText = document.getElementById('passkey-btn-text');
  const btnLoading = document.getElementById('passkey-btn-loading');
  const errorEl = document.getElementById('passkey-error');
  const assertionField = document.getElementById('passkey-assertion');
  const challengeTokenField = document.getElementById('passkey-challenge-token');

  // Check WebAuthn support
  if (!window.PublicKeyCredential) {
    showError(L.errorUnsupported || 'Your browser does not support Passkeys (WebAuthn).');
    if (loginBtn) loginBtn.disabled = true;
    return;
  }

  // Check secure context
  if (!window.isSecureContext) {
    showError(L.errorInsecure || 'Passkeys require a secure connection (HTTPS).');
    if (loginBtn) loginBtn.disabled = true;
    return;
  }

  // Detect failed passkey login from previous attempt
  checkForFailedPasskeyLogin();

  // Holds the AbortController of a pending conditional-mediation get() so an
  // explicit button click can cancel it first (only one credentials.get() may
  // be in flight at a time).
  let conditionalAbort = null;

  if (loginBtn) {
    loginBtn.addEventListener('click', handlePasskeyLogin);
  }

  // Conditional UI / Autofill: surface discoverable passkeys directly in the
  // standard username field's autofill menu, so a returning user does not have
  // to click the passkey button. Requires discoverable (resident) credentials
  // and browser support for conditional mediation; degrades silently to the
  // button otherwise. See passkeys.dev "Conditional UI".
  if (config.discoverableEnabled && usernameInput
      && typeof PublicKeyCredential.isConditionalMediationAvailable === 'function') {
    startConditionalLogin();
  }

  async function startConditionalLogin() {
    let available = false;
    try {
      available = await PublicKeyCredential.isConditionalMediationAvailable();
    } catch (e) {
      return;
    }
    if (!available) return;

    // Advertise WebAuthn autofill on the standard username field without
    // clobbering an existing autocomplete token.
    const existing = usernameInput.getAttribute('autocomplete') || '';
    if (existing.indexOf('webauthn') === -1) {
      usernameInput.setAttribute('autocomplete', (existing ? existing + ' ' : 'username ') + 'webauthn');
    }

    // Prefetch discoverable assertion options quietly: a 429/rate-limit or any
    // error here must NOT surface on page load — the explicit button remains.
    const optionsData = await fetchAssertionOptions('', true);
    if (!optionsData) return;

    const publicKeyOptions = buildPublicKeyOptions(optionsData.options);
    conditionalAbort = new AbortController();
    try {
      const assertion = await navigator.credentials.get({
        publicKey: publicKeyOptions,
        mediation: 'conditional',
        signal: conditionalAbort.signal,
      });
      conditionalAbort = null;
      // Discoverable login: username stays empty; the server resolves the user
      // from the credential ID.
      await verifyAndSubmit(encodeAssertion(assertion), optionsData.options, optionsData.challengeToken, '');
    } catch (err) {
      conditionalAbort = null;
      // Abort (explicit button took over) and NotAllowedError (user dismissed
      // the autofill) are normal for conditional UI — stay silent.
      if (err.name !== 'AbortError' && err.name !== 'NotAllowedError') {
        console.error('Passkey conditional login error:', err);
      }
    }
  }

  function checkForFailedPasskeyLogin() {
    try {
      if (sessionStorage.getItem('nr_passkey_attempt')) {
        sessionStorage.removeItem('nr_passkey_attempt');
        // Still on the login page after a passkey submission = auth failed.
        // TYPO3 does a POST-Redirect-GET after failed login, so the generic
        // error div (#t3-login-error) may not be present on the redirected page.
        showError(L.errorVerifyFailed || 'Passkey authentication failed. Your passkey was not accepted. Please try again or sign in with your password.');
        // Hide generic TYPO3 error if it exists
        const typo3Error = document.getElementById('t3-login-error');
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
    // Cancel a pending conditional get() first — only one credentials.get()
    // ceremony may run at a time.
    if (conditionalAbort) {
      conditionalAbort.abort();
      conditionalAbort = null;
    }
    const username = usernameInput ? usernameInput.value.trim() : '';

    if (!username && !config.discoverableEnabled) {
      showError(L.errorUsernameRequired || 'Please enter your username.');
      if (usernameInput) usernameInput.focus();
      return;
    }

    setLoading(true);

    try {
      // Step 1: Fetch assertion options from server
      const optionsData = await fetchAssertionOptions(username);
      if (!optionsData) {
        // fetchAssertionOptions already surfaced the error to the user
        setLoading(false);
        return;
      }

      // Step 2: Prepare options and call the WebAuthn API
      const publicKeyOptions = buildPublicKeyOptions(optionsData.options);
      const assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });

      // Step 3: Verify server-side, then submit the resulting login token.
      const credentialResponse = encodeAssertion(assertion);
      await verifyAndSubmit(credentialResponse, optionsData.options, optionsData.challengeToken, username);
    } catch (err) {
      setLoading(false);
      handleAuthError(err);
    }
  }

  /**
   * Verify the assertion at the /passkeys/login/verify endpoint. On success the
   * server returns a single-use login token, which is submitted through the
   * standard login form (the auth service consumes it — the challenge is
   * single-use and cannot be verified again). On the discoverable path a
   * credential the server does not know yields reason "unknown_credential", which
   * we report to the authenticator via the WebAuthn Signal API.
   */
  async function verifyAndSubmit(credentialResponse, options, challengeToken, username) {
    // Backward-compatible fallback: no verify endpoint injected → submit the raw
    // assertion through the login form as before.
    if (!config.loginVerifyUrl) {
      submitAssertion(credentialResponse, challengeToken, username);
      return;
    }

    let verifyResponse;
    try {
      verifyResponse = await fetch(config.loginVerifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          assertion: credentialResponse,
          challengeToken: challengeToken,
          username: username,
        }),
      });
    } catch (e) {
      setLoading(false);
      showError(L.errorGeneric || 'Authentication failed. Please try again.');
      return;
    }

    const data = await verifyResponse.json().catch(function () { return {}; });

    if (verifyResponse.ok && data.status === 'ok' && data.loginToken) {
      submitLoginToken(data.loginToken, username);
      return;
    }

    setLoading(false);

    // The server confirmed this credential ID is not (or no longer) registered
    // (discoverable path only — never for username-first, to keep the decoy
    // enumeration defence intact). Ask the authenticator to prune the orphan.
    if (data.reason === 'unknown_credential') {
      signalUnknownCredential(options.rpId, credentialResponse.id);
    }

    if (verifyResponse.status === 429) {
      showError(data.locked
        ? (L.errorLocked || 'Account temporarily locked. Please contact your administrator.')
        : (L.errorRateLimit || 'Too many attempts. Please try again later.'));
    } else {
      showError(data.error || L.errorGeneric || 'Authentication failed. Please try again.');
    }
  }

  /**
   * Submit a verified single-use login token through the standard TYPO3 login
   * form. The token is packed into userident as JSON; the auth service resolves
   * and consumes it.
   */
  function submitLoginToken(token, username) {
    const useridentField = document.querySelector('.t3js-login-userident-field');
    if (useridentField) {
      useridentField.value = JSON.stringify({ _type: 'passkey_token', token: token });
    }
    if (usernameInput) {
      usernameInput.value = username;
    }
    try { sessionStorage.setItem('nr_passkey_attempt', '1'); } catch (e) { /* ignore */ }
    loginForm.submit();
  }

  /**
   * Best-effort WebAuthn Signal API call: report a credential ID the server no
   * longer recognises so a supporting authenticator/password manager can remove
   * the orphaned passkey. Feature-detected and error-swallowing.
   */
  function signalUnknownCredential(rpId, credentialId) {
    if (!window.PublicKeyCredential
        || typeof PublicKeyCredential.signalUnknownCredential !== 'function'
        || !rpId || !credentialId) {
      return;
    }
    try {
      Promise.resolve(PublicKeyCredential.signalUnknownCredential({ rpId: rpId, credentialId: credentialId }))
        .catch(function () { /* best-effort: Signal API support varies */ });
    } catch (e) {
      /* best-effort */
    }
  }

  /**
   * Fetch assertion options from the server. On an error response the user is
   * informed and null is returned; otherwise the parsed options payload.
   *
   * When `silent` is true (conditional-UI prefetch on page load) errors are
   * swallowed and null is returned without surfacing a message — the explicit
   * button flow stays the visible path.
   */
  async function fetchAssertionOptions(username, silent) {
    let optionsResponse;
    try {
      optionsResponse = await fetch(optionsUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username: username }),
      });
    } catch (e) {
      if (!silent) showError(L.errorGeneric || 'Authentication failed. Please try again.');
      return null;
    }

    if (optionsResponse.ok) {
      return optionsResponse.json();
    }

    if (silent) {
      return null;
    }

    const data = await optionsResponse.json().catch(function () { return {}; });
    if (optionsResponse.status === 429) {
      showError(data.locked
        ? (L.errorLocked || 'Account temporarily locked. Please contact your administrator.')
        : (L.errorRateLimit || 'Too many attempts. Please try again later.'));
    } else {
      showError(data.error || L.errorGeneric || 'Authentication failed. Please try again.');
    }

    return null;
  }

  /**
   * Build the navigator.credentials.get() publicKey options from the server payload.
   */
  function buildPublicKeyOptions(options) {
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

    return publicKeyOptions;
  }

  /**
   * Encode the authenticator assertion into the JSON structure the server expects.
   */
  function encodeAssertion(assertion) {
    return {
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
  }

  /**
   * Pack the assertion + challenge token into the standard TYPO3 login form and submit it.
   *
   * The assertion is passed via the userident field. TYPO3 auth services receive login
   * data via $this->login['uident'] which maps to the userident POST field;
   * $GLOBALS['TYPO3_REQUEST'] is not available during the auth service chain, so custom
   * POST fields (passkey_assertion etc.) are inaccessible. Using userident is the standard
   * TYPO3 way to pass authentication credentials.
   */
  function submitAssertion(credentialResponse, challengeToken, username) {
    const passkeyPayload = JSON.stringify({
      _type: 'passkey',
      assertion: credentialResponse,
      challengeToken: challengeToken,
    });

    const useridentField = document.querySelector('.t3js-login-userident-field');
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
  }

  /**
   * Map a navigator.credentials.get() rejection to a user-facing error message.
   */
  function handleAuthError(err) {
    if (err.name === 'NotAllowedError') {
      showError(L.errorNotAllowed || 'Authentication was cancelled or no passkey found for this site. Have you registered a passkey?');
    } else if (err.name === 'SecurityError') {
      showError(L.errorSecurity || 'Security error. Please check your connection.');
    } else if (err.name === 'AbortError') {
      showError(L.errorCancelled || 'Authentication was cancelled.');
    } else {
      showError(L.errorGeneric || 'Authentication failed. Please try again.');
      console.error('Passkey login error:', err);
    }
  }

  function setLoading(loading) {
    if (loginBtn) loginBtn.disabled = loading;
    if (btnText) btnText.classList.toggle('d-none', loading);
    if (btnLoading) btnLoading.classList.toggle('d-none', !loading);
  }

  function showError(message) {
    if (errorEl) {
      // Make the live region visible/in the a11y tree BEFORE injecting text so
      // the aria-live="assertive" announcement fires reliably (NVDA/JAWS/VoiceOver
      // do not consistently announce text set on a display:none node).
      errorEl.classList.remove('d-none');
      errorEl.textContent = message;
    }
  }

  function hideError() {
    if (errorEl) {
      errorEl.classList.add('d-none');
      errorEl.textContent = '';
    }
  }
}

function buildPasskeyUI(labels) {
  labels = labels || {};
  const divider = document.createElement('div');
  divider.className = 'passkey-divider mb-2 mt-2';
  const hrLeft = document.createElement('hr');
  const orLabel = document.createElement('span');
  orLabel.className = 'passkey-divider-label';
  orLabel.textContent = labels.dividerOr || 'or';
  const hrRight = document.createElement('hr');
  divider.appendChild(hrLeft);
  divider.appendChild(orLabel);
  divider.appendChild(hrRight);

  const container = document.createElement('div');
  container.id = 'passkey-login-container';
  container.className = 'passkey-login';
  container.appendChild(divider);

  const formGroup = document.createElement('div');
  formGroup.className = 'form-group mb-2';
  const grid = document.createElement('div');
  grid.className = 'd-grid';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.id = 'passkey-login-btn';
  btn.className = 'btn btn-default btn-block w-100';

  // Icon is a static SVG constant (Material Symbols, Apache 2.0, no user input).
  // Parse it into a DOM node instead of assigning innerHTML so there is no XSS surface.
  const iconSpan = document.createElement('span');
  iconSpan.className = 'passkey-icon me-2';
  const iconSvg = new DOMParser().parseFromString(PASSKEY_ICON, 'image/svg+xml').documentElement;
  iconSpan.appendChild(document.importNode(iconSvg, true));
  const textSpan = document.createElement('span');
  textSpan.id = 'passkey-btn-text';
  textSpan.textContent = labels.signIn || 'Sign in with a passkey';
  const loadingSpan = document.createElement('span');
  loadingSpan.id = 'passkey-btn-loading';
  loadingSpan.className = 'd-none';
  const spinner = document.createElement('span');
  spinner.className = 'spinner-border spinner-border-sm me-2';
  spinner.setAttribute('role', 'status');
  loadingSpan.appendChild(spinner);
  loadingSpan.appendChild(document.createTextNode(labels.loading || 'Authenticating…'));

  btn.appendChild(iconSpan);
  btn.appendChild(textSpan);
  btn.appendChild(loadingSpan);
  grid.appendChild(btn);
  formGroup.appendChild(grid);
  container.appendChild(formGroup);

  // "What are passkeys?" help toggle
  const helpContent = document.createElement('div');
  helpContent.id = 'passkey-help-content';
  helpContent.className = 'alert alert-light small d-none mb-2';
  helpContent.textContent = labels.helpContent || 'Passkeys are a modern replacement for passwords. They use your device’s biometric sensors (fingerprint, face) or security keys to verify your identity. They’re faster and more secure than passwords because they can’t be phished or stolen.';

  const learnMore = document.createElement('a');
  learnMore.href = 'https://passkeys.dev';
  learnMore.target = '_blank';
  learnMore.rel = 'noopener noreferrer';
  learnMore.className = 'small d-block mt-1';
  learnMore.textContent = labels.helpLearnMore || 'Learn more about passkeys';
  helpContent.appendChild(document.createElement('br'));
  helpContent.appendChild(learnMore);

  const helpLink = document.createElement('a');
  helpLink.href = '#';
  helpLink.id = 'passkey-help-link';
  helpLink.className = 'passkey-help-link small text-muted d-block text-center mb-2';
  helpLink.textContent = labels.helpTitle || 'What are passkeys?';
  helpLink.addEventListener('click', function (e) {
    e.preventDefault();
    helpContent.classList.toggle('d-none');
  });

  container.appendChild(helpLink);
  container.appendChild(helpContent);

  const errorDiv = document.createElement('div');
  errorDiv.id = 'passkey-error';
  errorDiv.className = 'alert alert-danger d-none mb-2';
  errorDiv.setAttribute('role', 'alert');
  // Announce async WebAuthn errors to screen readers (A11Y-1).
  errorDiv.setAttribute('aria-live', 'assertive');
  errorDiv.setAttribute('aria-atomic', 'true');
  container.appendChild(errorDiv);

  const assertionInput = document.createElement('input');
  assertionInput.type = 'hidden';
  assertionInput.name = 'passkey_assertion';
  assertionInput.id = 'passkey-assertion';
  assertionInput.value = '';
  container.appendChild(assertionInput);

  const tokenInput = document.createElement('input');
  tokenInput.type = 'hidden';
  tokenInput.name = 'passkey_challenge_token';
  tokenInput.id = 'passkey-challenge-token';
  tokenInput.value = '';
  container.appendChild(tokenInput);

  return container;
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
