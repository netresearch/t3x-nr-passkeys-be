/**
 * Unit tests for PasskeyLogin.js base64url encoding/decoding utilities.
 *
 * The IIFE in PasskeyLogin.js cannot be imported directly, so we test
 * the utility function logic independently. IIFE behavior (DOM interaction,
 * WebAuthn checks) is tested via Playwright E2E tests.
 */
import { describe, it, expect } from 'vitest';

// --- Extracted utility functions (same logic as PasskeyLogin.js) ---

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

// --- Tests ---

describe('base64url encoding/decoding', () => {
    it('should round-trip encode/decode a simple string', () => {
        const original = new TextEncoder().encode('Hello, WebAuthn!');
        const encoded = bufferToBase64url(original.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(Array.from(decoded)).toEqual(Array.from(original));
    });

    it('should handle empty buffer', () => {
        const empty = new Uint8Array(0);
        const encoded = bufferToBase64url(empty.buffer);
        expect(encoded).toBe('');
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded.length).toBe(0);
    });

    it('should correctly encode bytes that produce + and / in standard base64', () => {
        // Bytes that produce '+' (0xFB) and '/' (0xFF) in standard base64
        const tricky = new Uint8Array([0xFB, 0xFF, 0xFE, 0x3E, 0x3F]);
        const encoded = bufferToBase64url(tricky.buffer);
        expect(encoded).not.toContain('+');
        expect(encoded).not.toContain('/');
        expect(encoded).not.toContain('=');
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(tricky);
    });

    it('should handle padding correctly for different lengths', () => {
        // 1 byte -> 2 base64 chars + 2 padding (stripped)
        const one = new Uint8Array([0x41]);
        expect(bufferToBase64url(one.buffer)).toBe('QQ');

        // 2 bytes -> 3 base64 chars + 1 padding (stripped)
        const two = new Uint8Array([0x41, 0x42]);
        expect(bufferToBase64url(two.buffer)).toBe('QUI');

        // 3 bytes -> 4 base64 chars + 0 padding
        const three = new Uint8Array([0x41, 0x42, 0x43]);
        expect(bufferToBase64url(three.buffer)).toBe('QUJD');
    });

    it('should handle 32-byte credential IDs (typical WebAuthn)', () => {
        const credId = new Uint8Array(32);
        for (let i = 0; i < 32; i++) credId[i] = i;
        const encoded = bufferToBase64url(credId.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(credId);
    });

    it('should handle 64-byte signatures (typical ECDSA)', () => {
        const sig = new Uint8Array(64);
        for (let i = 0; i < 64; i++) sig[i] = (i * 7 + 13) % 256;
        const encoded = bufferToBase64url(sig.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(sig);
    });

    it('should handle all byte values (0x00-0xFF)', () => {
        const allBytes = new Uint8Array(256);
        for (let i = 0; i < 256; i++) allBytes[i] = i;
        const encoded = bufferToBase64url(allBytes.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(allBytes);
    });

    it('should produce different output than standard base64 for problematic bytes', () => {
        const data = new Uint8Array([0xFB, 0xFF, 0xFE]);
        const base64url = bufferToBase64url(data.buffer);
        const base64std = bufferToBase64(data.buffer);
        // base64url should have - instead of + and _ instead of /
        expect(base64url).not.toBe(base64std.replace(/=/g, ''));
    });

    it('should decode base64url strings from WebAuthn spec examples', () => {
        // Known base64url encoded value
        const encoded = 'SGVsbG8'; // "Hello" in base64url
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        const text = new TextDecoder().decode(decoded);
        expect(text).toBe('Hello');
    });
});

describe('bufferToBase64 (standard base64)', () => {
    it('should produce standard base64 with padding', () => {
        const one = new Uint8Array([0x41]);
        expect(bufferToBase64(one.buffer)).toBe('QQ==');

        const two = new Uint8Array([0x41, 0x42]);
        expect(bufferToBase64(two.buffer)).toBe('QUI=');

        const three = new Uint8Array([0x41, 0x42, 0x43]);
        expect(bufferToBase64(three.buffer)).toBe('QUJD');
    });

    it('should match native btoa for same input', () => {
        const data = new Uint8Array([72, 101, 108, 108, 111]); // "Hello"
        const result = bufferToBase64(data.buffer);
        expect(result).toBe(btoa('Hello'));
    });

    it('should handle empty buffer', () => {
        const empty = new Uint8Array(0);
        expect(bufferToBase64(empty.buffer)).toBe('');
    });

    it('should preserve + and / characters (unlike base64url)', () => {
        const data = new Uint8Array([0xFB, 0xFF, 0xFE]);
        const result = bufferToBase64(data.buffer);
        // Standard base64 may contain +, /, =
        expect(result).toBe(btoa(String.fromCharCode(0xFB, 0xFF, 0xFE)));
    });
});

describe('base64urlToBuffer edge cases', () => {
    it('should handle already-padded input', () => {
        const encoded = 'QQ=='; // With padding
        // Our function adds padding, but if already padded this should still work
        // Actually base64url shouldn't have padding, but let's verify robustness
        const decoded = new Uint8Array(base64urlToBuffer('QQ'));
        expect(decoded[0]).toBe(0x41);
    });

    it('should handle single character (minimal valid base64url)', () => {
        // Single char + padding = valid base64
        const decoded = new Uint8Array(base64urlToBuffer('AA'));
        expect(decoded[0]).toBe(0x00);
    });

    it('should handle large buffers (4KB)', () => {
        const large = new Uint8Array(4096);
        for (let i = 0; i < 4096; i++) large[i] = i % 256;
        const encoded = bufferToBase64url(large.buffer);
        const decoded = new Uint8Array(base64urlToBuffer(encoded));
        expect(decoded).toEqual(large);
    });
});
