/**
 * Passkey Management - User Settings module for managing passkeys.
 *
 * Uses TYPO3 native APIs:
 * - AjaxRequest with sudoModeInterceptor for write operations
 * - Notification for user feedback
 * - Modal for confirmation dialogs
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { sudoModeInterceptor } from '@typo3/backend/security/sudo-mode-interceptor.js';

class PasskeyManagement {
  constructor() {
    this.container = document.getElementById('passkey-management-container');
    if (!this.container) {
      return;
    }

    this.listUrl = this.container.dataset.listUrl;
    this.registerOptionsUrl = this.container.dataset.registerOptionsUrl;
    this.registerVerifyUrl = this.container.dataset.registerVerifyUrl;
    this.renameUrl = this.container.dataset.renameUrl;
    this.removeUrl = this.container.dataset.removeUrl;

    this.listBody = document.getElementById('passkey-list-body');
    this.addBtn = document.getElementById('passkey-add-btn');
    this.labelInput = document.getElementById('passkey-label-input');
    this.warningEl = document.getElementById('passkey-single-warning');
    this.emptyEl = document.getElementById('passkey-empty');
    this.countEl = document.getElementById('passkey-count');

    // Use top-level window for WebAuthn API calls.
    // TYPO3 renders the Setup module inside an iframe (typo3-iframe-module).
    // Browser extensions like Bitwarden intercept navigator.credentials and fail
    // inside iframes with "Invalid sameOriginWithAncestors" because they cannot
    // determine the frame hierarchy. Using window.top avoids this entirely.
    this.credentialsAPI = navigator.credentials;
    try {
      if (window.top && window.top !== window && window.top.navigator && window.top.navigator.credentials) {
        this.credentialsAPI = window.top.navigator.credentials;
      }
    } catch (e) {
      // Cross-origin iframe - fall back to current window
    }

    // Check WebAuthn support
    if (!window.PublicKeyCredential) {
      Notification.warning('WebAuthn not supported', 'Your browser does not support Passkeys (WebAuthn).', 0);
      if (this.addBtn) {
        this.addBtn.disabled = true;
      }
      return;
    }

    // Check secure context (HTTPS required for WebAuthn)
    if (window.isSecureContext === false) {
      Notification.warning('HTTPS required', 'Passkeys require a secure connection (HTTPS).', 0);
      if (this.addBtn) {
        this.addBtn.disabled = true;
      }
      return;
    }

    if (this.addBtn) {
      this.addBtn.addEventListener('click', () => this.handleAddPasskey());
    }
    if (this.labelInput) {
      this.labelInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.handleAddPasskey();
        }
      });
    }

    this.loadPasskeys();
  }

  async loadPasskeys() {
    try {
      const response = await new AjaxRequest(this.listUrl).get();
      const data = await response.resolve();
      this.renderList(data.credentials, data.enforcementEnabled);
    } catch (error) {
      Notification.error('Load failed', 'Failed to load passkeys.');
    }
  }

  renderList(credentials, enforcementEnabled) {
    if (!this.listBody) {
      return;
    }

    while (this.listBody.firstChild) {
      this.listBody.removeChild(this.listBody.firstChild);
    }

    if (credentials.length === 0) {
      if (this.emptyEl) {
        this.emptyEl.classList.remove('d-none');
      }
      if (this.warningEl) {
        this.warningEl.classList.add('d-none');
      }
      if (this.countEl) {
        this.countEl.textContent = '0';
      }
      return;
    }

    if (this.emptyEl) {
      this.emptyEl.classList.add('d-none');
    }
    if (this.countEl) {
      this.countEl.textContent = String(credentials.length);
    }
    if (this.warningEl) {
      this.warningEl.classList.toggle('d-none', credentials.length > 1);
    }

    credentials.forEach((cred) => {
      const row = document.createElement('tr');

      // Label cell
      const labelCell = document.createElement('td');
      const labelSpan = document.createElement('span');
      labelSpan.className = 'passkey-label';
      labelSpan.textContent = cred.label || 'Unnamed';
      labelSpan.dataset.uid = cred.uid;
      labelSpan.addEventListener('dblclick', () => this.startRename(labelSpan, cred.uid));
      labelCell.appendChild(labelSpan);
      row.appendChild(labelCell);

      // Created date cell
      const createdCell = document.createElement('td');
      createdCell.textContent = cred.createdAt ? this.formatTimestamp(cred.createdAt) : '-';
      row.appendChild(createdCell);

      // Last used cell
      const lastUsedCell = document.createElement('td');
      lastUsedCell.textContent = cred.lastUsedAt ? this.formatTimestamp(cred.lastUsedAt) : 'Never';
      row.appendChild(lastUsedCell);

      // Actions cell
      const actionsCell = document.createElement('td');

      const renameBtn = document.createElement('button');
      renameBtn.className = 'btn btn-sm btn-outline-secondary me-1';
      renameBtn.textContent = 'Rename';
      renameBtn.addEventListener('click', () => this.startRename(labelSpan, cred.uid));
      actionsCell.appendChild(renameBtn);

      const removeBtn = document.createElement('button');
      removeBtn.className = 'btn btn-sm btn-outline-danger';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', () => this.handleRemove(cred.uid, cred.label));
      actionsCell.appendChild(removeBtn);

      row.appendChild(actionsCell);
      this.listBody.appendChild(row);
    });
  }

  async handleAddPasskey() {
    const trimmedLabel = (this.labelInput ? this.labelInput.value.trim() : '') || 'Passkey';
    this.setAddLoading(true);

    try {
      // Step 1: Get registration options (sudo mode protected)
      const optionsResponse = await new AjaxRequest(this.registerOptionsUrl)
        .addMiddleware(sudoModeInterceptor)
        .post({});
      const optionsData = await optionsResponse.resolve();
      const options = optionsData.options;
      const challengeToken = optionsData.challengeToken;

      // Step 2: Create credential with browser
      const publicKeyOptions = {
        challenge: this.base64urlToBuffer(options.challenge),
        rp: {
          name: options.rp.name,
          id: options.rp.id,
        },
        user: {
          id: this.base64urlToBuffer(options.user.id),
          name: options.user.name,
          displayName: options.user.displayName,
        },
        pubKeyCredParams: options.pubKeyCredParams.map((p) => ({ type: p.type, alg: p.alg })),
        timeout: options.timeout || 60000,
        attestation: options.attestation || 'none',
        authenticatorSelection: options.authenticatorSelection || {},
      };

      if (options.excludeCredentials) {
        publicKeyOptions.excludeCredentials = options.excludeCredentials.map((cred) => ({
          type: cred.type,
          id: this.base64urlToBuffer(cred.id),
          transports: cred.transports || [],
        }));
      }

      const credential = await this.credentialsAPI.create({ publicKey: publicKeyOptions });

      // Step 3: Encode and send to server (sudo mode protected)
      const credentialResponse = {
        id: this.bufferToBase64url(credential.rawId),
        rawId: this.bufferToBase64(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: this.bufferToBase64url(credential.response.clientDataJSON),
          attestationObject: this.bufferToBase64url(credential.response.attestationObject),
        },
      };

      if (credential.response.getTransports) {
        credentialResponse.response.transports = credential.response.getTransports();
      }

      await new AjaxRequest(this.registerVerifyUrl)
        .addMiddleware(sudoModeInterceptor)
        .post({
          credential: credentialResponse,
          challengeToken: challengeToken,
          label: trimmedLabel,
        });

      if (this.labelInput) {
        this.labelInput.value = '';
      }
      Notification.success('Passkey registered', 'Passkey registered successfully.');
      this.loadPasskeys();
    } catch (error) {
      if (error.name === 'NotAllowedError' || error.name === 'AbortError') {
        Notification.info('Cancelled', 'Registration was cancelled.');
      } else {
        Notification.error('Registration failed', error.message || 'Failed to register passkey.');
      }
    }

    this.setAddLoading(false);
  }

  startRename(labelSpan, uid) {
    const currentLabel = labelSpan.textContent;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control form-control-sm';
    input.value = currentLabel;
    input.maxLength = 128;

    labelSpan.textContent = '';
    labelSpan.appendChild(input);
    input.focus();
    input.select();

    const commitRename = async () => {
      const newLabel = input.value.trim();
      if (newLabel === '' || newLabel === currentLabel) {
        labelSpan.textContent = currentLabel;
        return;
      }

      try {
        await new AjaxRequest(this.renameUrl)
          .addMiddleware(sudoModeInterceptor)
          .post({ uid: uid, label: newLabel });
        labelSpan.textContent = newLabel;
        Notification.success('Passkey renamed', 'Passkey renamed successfully.');
      } catch (error) {
        labelSpan.textContent = currentLabel;
        Notification.error('Rename failed', error.message || 'Failed to rename passkey.');
      }
    };

    input.addEventListener('blur', commitRename);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      }
      if (e.key === 'Escape') {
        labelSpan.textContent = currentLabel;
      }
    });
  }

  handleRemove(uid, label) {
    const modal = Modal.show(
      'Remove passkey',
      'Are you sure you want to remove the passkey "' + (label || 'Unnamed') + '"?',
      SeverityEnum.warning,
      [
        {
          text: 'Cancel',
          active: true,
          btnClass: 'btn-default',
          name: 'cancel',
        },
        {
          text: 'Remove',
          btnClass: 'btn-danger',
          name: 'remove',
          trigger: async () => {
            try {
              await new AjaxRequest(this.removeUrl)
                .addMiddleware(sudoModeInterceptor)
                .post({ uid: uid });
              Notification.success('Passkey removed', 'Passkey removed successfully.');
              this.loadPasskeys();
            } catch (error) {
              Notification.error('Remove failed', error.message || 'Failed to remove passkey.');
            }
          },
        },
      ],
    );
    modal.addEventListener('button.clicked', () => {
      modal.hideModal();
    });
  }

  setAddLoading(loading) {
    if (this.addBtn) {
      this.addBtn.disabled = loading;
      this.addBtn.textContent = loading ? 'Registering...' : 'Add Passkey';
    }
    if (this.labelInput) {
      this.labelInput.disabled = loading;
    }
  }

  formatTimestamp(ts) {
    if (!ts) {
      return '-';
    }
    const d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
  }

  // Base64URL utilities
  base64urlToBuffer(base64url) {
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

  bufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }
}

export default new PasskeyManagement();
