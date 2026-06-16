/**
 * Base64URL / Base64 helpers for the WebAuthn ceremonies.
 *
 * Shared by PasskeyLogin.js and PasskeyManagement.js so the encoding/decoding
 * is implemented (and unit-tested) exactly once.
 */

/**
 * Decode a base64url string into an ArrayBuffer.
 *
 * @param {string} base64url
 * @returns {ArrayBuffer}
 */
export function base64urlToBuffer(base64url) {
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

/**
 * Encode an ArrayBuffer as a (padding-stripped) base64url string.
 *
 * @param {ArrayBuffer} buffer
 * @returns {string}
 */
export function bufferToBase64url(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * Encode an ArrayBuffer as a standard base64 string (with padding).
 *
 * @param {ArrayBuffer} buffer
 * @returns {string}
 */
export function bufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}
