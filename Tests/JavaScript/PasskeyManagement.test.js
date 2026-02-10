/**
 * Unit tests for PasskeyManagement.js utility functions.
 *
 * Tests the extracted utility function logic. IIFE behavior and DOM
 * interaction is tested via Playwright E2E tests.
 */
import { describe, it, expect } from 'vitest';

// --- Extracted utility functions (same logic as PasskeyManagement.js) ---

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

function formatTimestamp(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
}

// --- Tests ---

describe('base64url utilities (management module)', () => {
    it('should encode and decode credential IDs', () => {
        const credId = new Uint8Array([
            0x01, 0x23, 0x45, 0x67, 0x89, 0xAB, 0xCD, 0xEF,
            0xFE, 0xDC, 0xBA, 0x98, 0x76, 0x54, 0x32, 0x10,
        ]);
        const encoded = bufferToBase64url(credId.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(credId);
    });

    it('should produce URL-safe encoding (no +, /, =)', () => {
        const data = new Uint8Array(256);
        for (let i = 0; i < 256; i++) data[i] = i;
        const encoded = bufferToBase64url(data.buffer);
        expect(encoded).not.toMatch(/[+/=]/);
    });

    it('should handle attestation object encoding (typical WebAuthn registration)', () => {
        // Simulate a typical attestation object (~200 bytes)
        const attestation = new Uint8Array(200);
        for (let i = 0; i < 200; i++) attestation[i] = (i * 13 + 37) % 256;
        const encoded = bufferToBase64url(attestation.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(attestation);
    });

    it('should handle clientDataJSON encoding', () => {
        // clientDataJSON is typically UTF-8 encoded JSON
        const json = '{"type":"webauthn.create","challenge":"test","origin":"https://example.com"}';
        const data = new TextEncoder().encode(json);
        const encoded = bufferToBase64url(data.buffer);
        const decoded = new TextDecoder().decode(new Uint8Array(base64urlToBuffer(encoded)));
        expect(decoded).toBe(json);
    });
});

describe('formatTimestamp', () => {
    it('should return "-" for null', () => {
        expect(formatTimestamp(null)).toBe('-');
    });

    it('should return "-" for undefined', () => {
        expect(formatTimestamp(undefined)).toBe('-');
    });

    it('should return "-" for zero', () => {
        expect(formatTimestamp(0)).toBe('-');
    });

    it('should format a known timestamp', () => {
        // 2024-01-15 12:00:00 UTC = 1705320000
        const result = formatTimestamp(1705320000);
        expect(result).toContain('2024');
        expect(result.length).toBeGreaterThan(5);
    });

    it('should format recent timestamp with current year', () => {
        const now = Math.floor(Date.now() / 1000);
        const result = formatTimestamp(now);
        expect(result).toContain(new Date().getFullYear().toString());
    });

    it('should include both date and time', () => {
        const ts = 1705320000;
        const result = formatTimestamp(ts);
        // Should contain date separator and time separator
        expect(result).toMatch(/\d/);
        expect(result.split(' ').length).toBeGreaterThanOrEqual(2);
    });

    it('should handle very old timestamps', () => {
        // Jan 1, 2000 = 946684800
        const result = formatTimestamp(946684800);
        expect(result).toContain('2000');
    });

    it('should handle negative timestamp (pre-epoch)', () => {
        // Should not crash, even though dates before epoch are unusual
        const result = formatTimestamp(-1);
        expect(typeof result).toBe('string');
        expect(result.length).toBeGreaterThan(0);
    });
});

describe('bufferToBase64 vs bufferToBase64url', () => {
    it('should differ for bytes that encode to + or /', () => {
        const data = new Uint8Array([0xFB, 0xFF, 0xFE]);
        const base64std = bufferToBase64(data.buffer);
        const base64url = bufferToBase64url(data.buffer);

        // Standard should have + or / or =
        expect(base64std).toMatch(/[+/=]/);
        // URL-safe should not
        expect(base64url).not.toMatch(/[+/=]/);
    });

    it('should produce identical output for safe bytes', () => {
        // "ABC" encodes to "QUJD" in both variants (no problematic chars)
        const data = new Uint8Array([0x41, 0x42, 0x43]);
        const base64std = bufferToBase64(data.buffer);
        const base64url = bufferToBase64url(data.buffer);
        expect(base64std).toBe('QUJD');
        expect(base64url).toBe('QUJD');
    });
});
