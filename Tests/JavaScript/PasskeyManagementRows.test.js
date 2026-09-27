/**
 * Row markup of the shipped PasskeyManagement module: the classes each row
 * button and badge carries have to exist in TYPO3's backend.css, or the element
 * renders without the intended styling in the light and dark schemes.
 */
import { describe, it, expect, beforeEach } from 'vitest';

const { default: management } = await import('@netresearch/nr-passkeys-be/PasskeyManagement.js');

function renderRows(credentials) {
    document.body.replaceChildren();
    const table = document.createElement('table');
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    document.body.appendChild(table);
    management.listBody = tbody;
    management.emptyEl = null;
    management.warningEl = null;
    management.countEl = null;
    management.renderList(credentials, false);
    return tbody;
}

describe('PasskeyManagement row markup', () => {
    let body;

    beforeEach(() => {
        body = renderRows([{ uid: 7, label: 'Laptop', createdAt: 1700000000, lastUsedAt: 0, discoverable: false }]);
    });

    it('renders the rename button as core btn-default', () => {
        const [rename] = body.querySelectorAll('button');
        expect(rename.className).toBe('btn btn-sm btn-default me-1');
    });

    it('renders the remove button as core btn-danger', () => {
        const [, remove] = body.querySelectorAll('button');
        expect(remove.className).toBe('btn btn-sm btn-danger');
    });

    it('uses no btn-outline-* class, which core backend.css does not define', () => {
        body.querySelectorAll('button').forEach((b) => expect(b.className).not.toMatch(/btn-outline-/));
    });

    it('renders the "no autofill" hint as a core badge-default, not a fixed bg-* colour', () => {
        const badge = body.querySelector('.badge');
        expect(badge.className).toBe('badge badge-default ms-2');
    });
});
