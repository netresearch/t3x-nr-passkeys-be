/**
 * Passkey Admin Info - FormEngine element for managing passkeys in be_users records.
 *
 * Handles revoke, revoke-all, and unlock interactions via AJAX with sudo mode.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import RegularEvent from '@typo3/core/event/regular-event.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { sudoModeInterceptor } from '@typo3/backend/security/sudo-mode-interceptor.js';

class PasskeyAdminInfo {
  constructor(selector, options) {
    this.options = options || {};
    this.fullElement = null;
    this.request = null;

    DocumentService.ready().then((document) => {
      this.fullElement = document.querySelector(selector);
      if (!this.fullElement) {
        return;
      }
      this.registerEvents();
    });
  }

  registerEvents() {
    // Revoke individual passkey buttons
    this.fullElement.querySelectorAll('.t3js-passkey-revoke-button').forEach((button) => {
      new RegularEvent('click', (event) => {
        event.preventDefault();
        this.confirmAndRevoke(button);
      }).bindTo(button);
    });

    // Revoke all passkeys button
    const revokeAllButton = this.fullElement.querySelector('.t3js-passkey-revoke-all-button');
    if (revokeAllButton) {
      new RegularEvent('click', (event) => {
        event.preventDefault();
        this.confirmAndRevokeAll(revokeAllButton);
      }).bindTo(revokeAllButton);
    }

    // Unlock account button
    const unlockButton = this.fullElement.querySelector('.t3js-passkey-unlock-button');
    if (unlockButton) {
      new RegularEvent('click', (event) => {
        event.preventDefault();
        this.confirmAndUnlock(unlockButton);
      }).bindTo(unlockButton);
    }
  }

  confirmAndRevoke(button) {
    const credentialUid = parseInt(button.dataset.credentialUid, 10);
    const modal = Modal.show(
      button.dataset.confirmationTitle || 'Revoke passkey',
      button.dataset.confirmationContent || 'Are you sure you want to revoke this passkey?',
      SeverityEnum.warning,
      [
        {
          text: button.dataset.confirmationCancelText || 'Cancel',
          active: true,
          btnClass: 'btn-default',
          name: 'cancel',
        },
        {
          text: button.dataset.confirmationRevokeText || 'Revoke',
          btnClass: 'btn-warning',
          name: 'revoke',
          trigger: () => {
            this.sendRevokeRequest(credentialUid);
          },
        },
      ],
    );
    modal.addEventListener('button.clicked', () => {
      modal.hideModal();
    });
  }

  confirmAndRevokeAll(button) {
    const modal = Modal.show(
      button.dataset.confirmationTitle || 'Revoke all passkeys',
      button.dataset.confirmationContent || 'Are you sure?',
      SeverityEnum.warning,
      [
        {
          text: button.dataset.confirmationCancelText || 'Cancel',
          active: true,
          btnClass: 'btn-default',
          name: 'cancel',
        },
        {
          text: button.dataset.confirmationRevokeText || 'Revoke all passkeys',
          btnClass: 'btn-danger',
          name: 'revokeAll',
          trigger: () => {
            this.sendRevokeAllRequest();
          },
        },
      ],
    );
    modal.addEventListener('button.clicked', () => {
      modal.hideModal();
    });
  }

  confirmAndUnlock(button) {
    const modal = Modal.show(
      button.dataset.confirmationTitle || 'Unlock account',
      button.dataset.confirmationContent || 'Reset the rate limiter for this user?',
      SeverityEnum.info,
      [
        {
          text: button.dataset.confirmationCancelText || 'Cancel',
          active: true,
          btnClass: 'btn-default',
          name: 'cancel',
        },
        {
          text: button.dataset.confirmationUnlockText || 'Unlock account',
          btnClass: 'btn-warning',
          name: 'unlock',
          trigger: () => {
            this.sendUnlockRequest();
          },
        },
      ],
    );
    modal.addEventListener('button.clicked', () => {
      modal.hideModal();
    });
  }

  sendRevokeRequest(credentialUid) {
    if (this.request instanceof AjaxRequest) {
      this.request.abort();
    }
    this.request = new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_remove).addMiddleware(sudoModeInterceptor);
    this.request
      .post({
        beUserUid: this.options.userId,
        credentialUid: credentialUid,
      })
      .then(async (response) => {
        const data = await response.resolve();
        if (data.status === 'ok') {
          Notification.success('Passkey revoked');
          this.markItemAsRevoked(credentialUid);
          this.updateStatusAfterRevoke();
        } else {
          Notification.error('Failed to revoke passkey', data.error || '');
        }
      })
      .catch(() => {
        Notification.error('Request failed');
      })
      .finally(() => {
        this.request = null;
      });
  }

  sendRevokeAllRequest() {
    if (this.request instanceof AjaxRequest) {
      this.request.abort();
    }
    this.request = new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_revoke_all).addMiddleware(
      sudoModeInterceptor,
    );
    this.request
      .post({
        beUserUid: this.options.userId,
      })
      .then(async (response) => {
        const data = await response.resolve();
        if (data.status === 'ok') {
          Notification.success('All passkeys revoked (' + data.revokedCount + ')');
          this.markAllItemsAsRevoked();
        } else {
          Notification.error('Failed to revoke passkeys', data.error || '');
        }
      })
      .catch(() => {
        Notification.error('Request failed');
      })
      .finally(() => {
        this.request = null;
      });
  }

  sendUnlockRequest() {
    if (this.request instanceof AjaxRequest) {
      this.request.abort();
    }
    this.request = new AjaxRequest(TYPO3.settings.ajaxUrls.passkeys_admin_unlock).addMiddleware(sudoModeInterceptor);
    this.request
      .post({
        beUserUid: this.options.userId,
        username: this.options.username,
      })
      .then(async (response) => {
        const data = await response.resolve();
        if (data.status === 'ok') {
          Notification.success('Account unlocked');
        } else {
          Notification.error('Failed to unlock account', data.error || '');
        }
      })
      .catch(() => {
        Notification.error('Request failed');
      })
      .finally(() => {
        this.request = null;
      });
  }

  markItemAsRevoked(credentialUid) {
    const item = this.fullElement.querySelector('#passkey-credential-' + credentialUid);
    if (!item) {
      return;
    }
    // Replace "Active" badge with "Revoked" badge
    const activeBadge = item.querySelector('.badge-success');
    if (activeBadge) {
      activeBadge.classList.remove('badge-success');
      activeBadge.classList.add('badge-danger');
      activeBadge.textContent = 'Revoked';
    }
    // Remove the per-item revoke button
    const revokeBtn = item.querySelector('.t3js-passkey-revoke-button');
    if (revokeBtn) {
      revokeBtn.remove();
    }
    item.dataset.passkeyStatus = 'revoked';
  }

  updateStatusAfterRevoke() {
    const list = this.fullElement.querySelector('.t3js-passkey-credentials-list');
    const remainingActive = list
      ? list.querySelectorAll('li:not([data-passkey-status="revoked"])').length
      : 0;
    if (remainingActive === 0) {
      this.updateStatusBadge();
    }
    // Disable "revoke all" button when no active credentials remain
    const revokeAllBtn = this.fullElement.querySelector('.t3js-passkey-revoke-all-button');
    if (revokeAllBtn && remainingActive === 0) {
      revokeAllBtn.classList.add('disabled');
      revokeAllBtn.setAttribute('disabled', 'disabled');
    }
  }

  markAllItemsAsRevoked() {
    const list = this.fullElement.querySelector('.t3js-passkey-credentials-list');
    if (!list) {
      return;
    }
    list.querySelectorAll('li:not([data-passkey-status="revoked"])').forEach((item) => {
      const activeBadge = item.querySelector('.badge-success');
      if (activeBadge) {
        activeBadge.classList.remove('badge-success');
        activeBadge.classList.add('badge-danger');
        activeBadge.textContent = 'Revoked';
      }
      const revokeBtn = item.querySelector('.t3js-passkey-revoke-button');
      if (revokeBtn) {
        revokeBtn.remove();
      }
      item.dataset.passkeyStatus = 'revoked';
    });
    const revokeAllBtn = this.fullElement.querySelector('.t3js-passkey-revoke-all-button');
    if (revokeAllBtn) {
      revokeAllBtn.classList.add('disabled');
      revokeAllBtn.setAttribute('disabled', 'disabled');
    }
    this.updateStatusBadge();
  }

  updateStatusBadge() {
    const statusLabel = this.fullElement.closest('fieldset')?.querySelector('.t3js-passkey-status-label') ?? null;
    if (statusLabel && statusLabel.dataset.alternativeLabel) {
      statusLabel.innerText = statusLabel.dataset.alternativeLabel;
      statusLabel.classList.remove('badge-success');
      statusLabel.classList.add('badge-danger');
    }
  }
}

export default PasskeyAdminInfo;
