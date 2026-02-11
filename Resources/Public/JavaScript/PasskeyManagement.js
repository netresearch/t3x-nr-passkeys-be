/**
 * Passkey Management - User Settings module for managing passkeys
 *
 * Handles:
 * - Listing registered passkeys
 * - Registering new passkeys
 * - Renaming passkeys
 * - Removing passkeys
 * - Sudo mode re-authentication for write operations
 */
(function () {
  'use strict';

  function init() {
    const container = document.getElementById('passkey-management-container');
    if (!container) return;

  const listUrl = container.dataset.listUrl || '/typo3/ajax/passkeys/manage/list';
  const registerOptionsUrl = container.dataset.registerOptionsUrl || '/typo3/ajax/passkeys/manage/registration/options';
  const registerVerifyUrl = container.dataset.registerVerifyUrl || '/typo3/ajax/passkeys/manage/registration/verify';
  const renameUrl = container.dataset.renameUrl || '/typo3/ajax/passkeys/manage/rename';
  const removeUrl = container.dataset.removeUrl || '/typo3/ajax/passkeys/manage/remove';

  const listBody = document.getElementById('passkey-list-body');
  const addBtn = document.getElementById('passkey-add-btn');
  const labelInput = document.getElementById('passkey-label-input');
  const messageEl = document.getElementById('passkey-message');
  const warningEl = document.getElementById('passkey-single-warning');
  const emptyEl = document.getElementById('passkey-empty');
  const countEl = document.getElementById('passkey-count');

  // Use top-level window for WebAuthn API calls.
  // TYPO3 renders the Setup module inside an iframe (typo3-iframe-module).
  // Browser extensions like Bitwarden intercept navigator.credentials and fail
  // inside iframes with "Invalid sameOriginWithAncestors" because they cannot
  // determine the frame hierarchy. Using window.top avoids this entirely.
  var credentialsAPI = navigator.credentials;
  try {
    if (window.top && window.top !== window && window.top.navigator && window.top.navigator.credentials) {
      credentialsAPI = window.top.navigator.credentials;
    }
  } catch (e) {
    // Cross-origin iframe - fall back to current window (should not happen in TYPO3 backend)
  }

  // Check WebAuthn support
  if (!window.PublicKeyCredential) {
    showMessage('Your browser does not support Passkeys (WebAuthn).', 'warning');
    if (addBtn) addBtn.disabled = true;
    return;
  }

  if (addBtn) {
    addBtn.addEventListener('click', handleAddPasskey);
  }

  if (labelInput) {
    labelInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleAddPasskey();
      }
    });
  }

  // Initial load
  loadPasskeys();

  // --- Sudo Mode handling ---

  /**
   * Wrapper around fetch() that handles TYPO3 sudo mode 422 responses.
   *
   * When a route is protected by sudo mode and the user has not verified
   * recently, TYPO3 returns HTTP 422 with a JSON body containing
   * `sudoModeInitialization`. This function intercepts that response,
   * shows a password verification dialog, submits the password to TYPO3's
   * sudo mode verify endpoint, and retries the original request on success.
   */
  async function sudoFetch(url, options) {
    var response = await fetch(url, options);
    if (response.status !== 422) return response;

    var data;
    try {
      data = await response.clone().json();
    } catch (e) {
      return response;
    }

    if (!data.sudoModeInitialization) return response;

    var init = data.sudoModeInitialization;
    var password = await showSudoModeDialog(init.labels || {});
    if (!password) throw new Error('Verification cancelled');

    var verifyResponse = await fetch(init.verifyActionUri, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'password=' + encodeURIComponent(password),
    });

    if (!verifyResponse.ok) {
      var verifyData;
      try {
        verifyData = await verifyResponse.json();
      } catch (e) {
        verifyData = {};
      }
      throw new Error(verifyData.message || 'Password verification failed');
    }

    // Retry original request -- grant is now stored in session
    return fetch(url, options);
  }

  /**
   * Show a Bootstrap 5 modal dialog for sudo mode password verification.
   * Returns a Promise that resolves with the entered password or null if cancelled.
   * Built entirely with safe DOM methods (no innerHTML).
   */
  function showSudoModeDialog(labels) {
    return new Promise(function (resolve) {
      var title = labels.title || 'Verify your identity';
      var description = labels.description || 'Please enter your password to continue.';
      var confirmLabel = labels.confirm || 'Verify';
      var cancelLabel = labels.cancel || 'Cancel';
      var passwordLabel = labels.password || 'Password';

      // Create modal backdrop
      var backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade show';

      // Create modal wrapper
      var modal = document.createElement('div');
      modal.className = 'modal fade show';
      modal.style.display = 'block';
      modal.setAttribute('tabindex', '-1');
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');

      var dialogId = 'sudo-mode-dialog-' + Date.now();

      // Build modal structure with DOM methods
      var modalDialog = document.createElement('div');
      modalDialog.className = 'modal-dialog modal-dialog-centered';
      modalDialog.setAttribute('role', 'document');

      var modalContent = document.createElement('div');
      modalContent.className = 'modal-content';

      // Header
      var modalHeader = document.createElement('div');
      modalHeader.className = 'modal-header';

      var titleEl = document.createElement('h5');
      titleEl.className = 'modal-title';
      titleEl.id = dialogId;
      titleEl.textContent = title;
      modalHeader.appendChild(titleEl);

      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'btn-close';
      closeBtn.setAttribute('data-action', 'cancel');
      closeBtn.setAttribute('aria-label', cancelLabel);
      modalHeader.appendChild(closeBtn);

      // Body
      var modalBody = document.createElement('div');
      modalBody.className = 'modal-body';

      var descEl = document.createElement('p');
      descEl.textContent = description;
      modalBody.appendChild(descEl);

      var errorEl = document.createElement('div');
      errorEl.className = 'alert alert-danger d-none';
      errorEl.setAttribute('role', 'alert');
      modalBody.appendChild(errorEl);

      var formGroup = document.createElement('div');
      formGroup.className = 'mb-3';

      var labelEl = document.createElement('label');
      labelEl.className = 'form-label';
      labelEl.textContent = passwordLabel;

      var passwordInput = document.createElement('input');
      passwordInput.type = 'password';
      passwordInput.className = 'form-control';
      passwordInput.autocomplete = 'current-password';

      labelEl.setAttribute('for', passwordInput.id || '');
      formGroup.appendChild(labelEl);
      formGroup.appendChild(passwordInput);
      modalBody.appendChild(formGroup);

      // Footer
      var modalFooter = document.createElement('div');
      modalFooter.className = 'modal-footer';

      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn btn-default';
      cancelBtn.setAttribute('data-action', 'cancel');
      cancelBtn.textContent = cancelLabel;
      modalFooter.appendChild(cancelBtn);

      var confirmBtn = document.createElement('button');
      confirmBtn.type = 'button';
      confirmBtn.className = 'btn btn-primary';
      confirmBtn.setAttribute('data-action', 'confirm');
      confirmBtn.textContent = confirmLabel;
      modalFooter.appendChild(confirmBtn);

      // Assemble
      modalContent.appendChild(modalHeader);
      modalContent.appendChild(modalBody);
      modalContent.appendChild(modalFooter);
      modalDialog.appendChild(modalContent);
      modal.appendChild(modalDialog);
      modal.setAttribute('aria-labelledby', dialogId);

      document.body.appendChild(backdrop);
      document.body.appendChild(modal);
      passwordInput.focus();

      function cleanup() {
        if (modal.parentNode) modal.parentNode.removeChild(modal);
        if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
      }

      function handleConfirm() {
        var value = passwordInput.value;
        if (!value) {
          while (errorEl.firstChild) errorEl.removeChild(errorEl.firstChild);
          errorEl.appendChild(document.createTextNode('Please enter your password.'));
          errorEl.classList.remove('d-none');
          return;
        }
        // Clear password from input before removing DOM element
        passwordInput.value = '';
        cleanup();
        resolve(value);
      }

      function handleCancel() {
        passwordInput.value = '';
        cleanup();
        resolve(null);
      }

      // Event delegation for buttons
      modal.addEventListener('click', function (e) {
        var action = e.target.getAttribute('data-action');
        if (action === 'confirm') handleConfirm();
        if (action === 'cancel') handleCancel();
      });

      // Enter key submits, Escape cancels
      passwordInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          handleConfirm();
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          handleCancel();
        }
      });
    });
  }

  // --- End Sudo Mode handling ---

  async function loadPasskeys() {
    try {
      const response = await fetch(listUrl, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
      });

      if (!response.ok) {
        showMessage('Failed to load passkeys.', 'danger');
        return;
      }

      const data = await response.json();
      renderList(data.credentials, data.enforcementEnabled);
    } catch (err) {
      showMessage('Failed to load passkeys.', 'danger');
      console.error('Load passkeys error:', err);
    }
  }

  function renderList(credentials, enforcementEnabled) {
    if (!listBody) return;

    // Clear existing rows safely using DOM methods
    while (listBody.firstChild) {
      listBody.removeChild(listBody.firstChild);
    }

    if (credentials.length === 0) {
      if (emptyEl) emptyEl.classList.remove('d-none');
      if (warningEl) warningEl.classList.add('d-none');
      if (countEl) countEl.textContent = '0';
      return;
    }

    if (emptyEl) emptyEl.classList.add('d-none');
    if (countEl) countEl.textContent = String(credentials.length);

    // Show warning if only 1 key
    if (warningEl) {
      warningEl.classList.toggle('d-none', credentials.length > 1);
    }

    credentials.forEach(function (cred) {
      var row = document.createElement('tr');

      // Label cell
      var labelCell = document.createElement('td');
      var labelSpan = document.createElement('span');
      labelSpan.className = 'passkey-label';
      labelSpan.textContent = cred.label || 'Unnamed';
      labelSpan.dataset.uid = cred.uid;
      labelSpan.addEventListener('dblclick', function () {
        startRename(labelSpan, cred.uid);
      });
      labelCell.appendChild(labelSpan);
      row.appendChild(labelCell);

      // Created date cell
      var createdCell = document.createElement('td');
      createdCell.textContent = cred.createdAt ? formatTimestamp(cred.createdAt) : '-';
      row.appendChild(createdCell);

      // Last used cell
      var lastUsedCell = document.createElement('td');
      lastUsedCell.textContent = cred.lastUsedAt ? formatTimestamp(cred.lastUsedAt) : 'Never';
      row.appendChild(lastUsedCell);

      // Actions cell
      var actionsCell = document.createElement('td');

      var renameBtn = document.createElement('button');
      renameBtn.className = 'btn btn-sm btn-outline-secondary me-1';
      renameBtn.textContent = 'Rename';
      renameBtn.addEventListener('click', function () {
        startRename(labelSpan, cred.uid);
      });
      actionsCell.appendChild(renameBtn);

      var removeBtn = document.createElement('button');
      removeBtn.className = 'btn btn-sm btn-outline-danger';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', function () {
        handleRemove(cred.uid, cred.label);
      });
      actionsCell.appendChild(removeBtn);

      row.appendChild(actionsCell);
      listBody.appendChild(row);
    });
  }

  async function handleAddPasskey() {
    hideMessage();
    var trimmedLabel = (labelInput ? labelInput.value.trim() : '') || 'Passkey';
    setAddLoading(true);

    try {
      // Step 1: Get registration options (sudo mode protected)
      var optionsResponse = await sudoFetch(registerOptionsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      });

      if (!optionsResponse.ok) {
        showMessage('Failed to start registration.', 'danger');
        setAddLoading(false);
        return;
      }

      var optionsData = await optionsResponse.json();
      var options = optionsData.options;
      var challengeToken = optionsData.challengeToken;

      // Step 2: Create credential with browser
      var publicKeyOptions = {
        challenge: base64urlToBuffer(options.challenge),
        rp: {
          name: options.rp.name,
          id: options.rp.id,
        },
        user: {
          id: base64urlToBuffer(options.user.id),
          name: options.user.name,
          displayName: options.user.displayName,
        },
        pubKeyCredParams: options.pubKeyCredParams.map(function (p) {
          return { type: p.type, alg: p.alg };
        }),
        timeout: options.timeout || 60000,
        attestation: options.attestation || 'none',
        authenticatorSelection: options.authenticatorSelection || {},
      };

      if (options.excludeCredentials) {
        publicKeyOptions.excludeCredentials = options.excludeCredentials.map(function (cred) {
          return {
            type: cred.type,
            id: base64urlToBuffer(cred.id),
            transports: cred.transports || [],
          };
        });
      }

      var credential = await credentialsAPI.create({
        publicKey: publicKeyOptions,
      });

      // Step 3: Encode and send to server (sudo mode protected)
      var credentialResponse = {
        id: bufferToBase64url(credential.rawId),
        rawId: bufferToBase64(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
          attestationObject: bufferToBase64url(credential.response.attestationObject),
        },
      };

      // Include transports if available
      if (credential.response.getTransports) {
        credentialResponse.response.transports = credential.response.getTransports();
      }

      var verifyResponse = await sudoFetch(registerVerifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          credential: credentialResponse,
          challengeToken: challengeToken,
          label: trimmedLabel,
        }),
      });

      if (!verifyResponse.ok) {
        showMessage('Registration failed. Please try again.', 'danger');
        setAddLoading(false);
        return;
      }

      if (labelInput) labelInput.value = '';
      showMessage('Passkey registered successfully!', 'success');
      loadPasskeys(); // Refresh list
    } catch (err) {
      if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
        showMessage('Registration was cancelled.', 'info');
      } else if (err.message === 'Verification cancelled') {
        showMessage('Password verification was cancelled.', 'info');
      } else {
        showMessage('Failed to register passkey. Please try again.', 'danger');
        console.error('Register passkey error:', err);
      }
    }

    setAddLoading(false);
  }

  function startRename(labelSpan, uid) {
    var currentLabel = labelSpan.textContent;
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control form-control-sm';
    input.value = currentLabel;
    input.maxLength = 128;

    labelSpan.textContent = '';
    labelSpan.appendChild(input);
    input.focus();
    input.select();

    async function commitRename() {
      var newLabel = input.value.trim();
      if (newLabel === '' || newLabel === currentLabel) {
        labelSpan.textContent = currentLabel;
        return;
      }

      try {
        var response = await sudoFetch(renameUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ uid: uid, label: newLabel }),
        });

        if (response.ok) {
          labelSpan.textContent = newLabel;
          showMessage('Passkey renamed.', 'success');
        } else {
          labelSpan.textContent = currentLabel;
          showMessage('Failed to rename passkey.', 'danger');
        }
      } catch (err) {
        labelSpan.textContent = currentLabel;
        if (err.message === 'Verification cancelled') {
          showMessage('Password verification was cancelled.', 'info');
        } else {
          showMessage('Failed to rename passkey.', 'danger');
        }
      }
    }

    input.addEventListener('blur', commitRename);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      }
      if (e.key === 'Escape') {
        labelSpan.textContent = currentLabel;
      }
    });
  }

  async function handleRemove(uid, label) {
    if (!confirm('Are you sure you want to remove the passkey "' + (label || 'Unnamed') + '"?')) {
      return;
    }

    try {
      var response = await sudoFetch(removeUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ uid: uid }),
      });

      if (!response.ok) {
        var data = await response.json().catch(function () { return {}; });
        showMessage(data.error || 'Failed to remove passkey.', 'danger');
        return;
      }

      showMessage('Passkey removed.', 'success');
      loadPasskeys();
    } catch (err) {
      if (err.message === 'Verification cancelled') {
        showMessage('Password verification was cancelled.', 'info');
      } else {
        showMessage('Failed to remove passkey.', 'danger');
        console.error('Remove passkey error:', err);
      }
    }
  }

  function setAddLoading(loading) {
    if (addBtn) {
      addBtn.disabled = loading;
      addBtn.textContent = loading ? 'Registering...' : 'Add Passkey';
    }
    if (labelInput) {
      labelInput.disabled = loading;
    }
  }

  var hideTimeout = null;

  function showMessage(text, type) {
    if (!messageEl) return;

    if (hideTimeout) {
      clearTimeout(hideTimeout);
      hideTimeout = null;
    }

    // Clear previous content safely
    while (messageEl.firstChild) {
      messageEl.removeChild(messageEl.firstChild);
    }

    messageEl.className = 'alert alert-' + type + ' alert-dismissible';
    messageEl.classList.remove('d-none');

    var textNode = document.createTextNode(text);
    messageEl.appendChild(textNode);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.addEventListener('click', hideMessage);
    messageEl.appendChild(closeBtn);

    // Only auto-hide success and info messages
    if (type === 'success' || type === 'info') {
      hideTimeout = setTimeout(hideMessage, 5000);
    }
  }

  function hideMessage() {
    if (!messageEl) return;
    messageEl.classList.add('d-none');
    if (hideTimeout) {
      clearTimeout(hideTimeout);
      hideTimeout = null;
    }
  }

  function formatTimestamp(ts) {
    if (!ts) return '-';
    var d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
  }

  // Base64URL utilities
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
  } // end init()

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
