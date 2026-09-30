/**
 * Row markup of the shipped PasskeyManagement module: the classes each row
 * button and badge carries have to exist in TYPO3's backend.css, or the element
 * renders without the intended styling in the light and dark schemes.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

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

describe('PasskeyManagement rename input', () => {
    const xlf = readFileSync(resolve(process.cwd(), 'Resources/Private/Language/locallang.xlf'), 'utf8');

    afterEach(() => {
        delete globalThis.TYPO3;
    });

    it('names the inline rename input from its language label', () => {
        // The label key must exist, or the fallback would hide a misspelt key.
        const match = xlf.match(/<trans-unit id="js\.manage\.rename\.input"[^>]*>\s*<source>([^<]+)<\/source>/);
        expect(match).not.toBeNull();
        globalThis.TYPO3 = { lang: { 'js.manage.rename.input': 'Label from XLIFF' } };

        const body = renderRows([{ uid: 7, label: 'Laptop', createdAt: 1700000000, lastUsedAt: 0 }]);
        const span = body.querySelector('.passkey-label');
        management.startRename(span, 7);
        const input = span.querySelector('input');

        expect(input.getAttribute('aria-label')).toBe('Label from XLIFF');
    });

    it('falls back to the English label when no translation is loaded', () => {
        const body = renderRows([{ uid: 7, label: 'Laptop', createdAt: 1700000000, lastUsedAt: 0 }]);
        const span = body.querySelector('.passkey-label');
        management.startRename(span, 7);

        expect(span.querySelector('input').getAttribute('aria-label')).toBe('New name for this passkey');
    });
});
