/**
 * Stand-in for @typo3/core/ajax/ajax-request.js (see vitest.config.mjs).
 *
 * Every request a module sends is recorded in `requests` with its URL, method,
 * body and middlewares. The outcome comes from the responder a test sets with
 * `respondWith()`: its return value becomes the JSON the response resolves to,
 * and whatever it throws becomes the rejection, as a failed AjaxRequest would.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
export const requests = [];

const defaultResponder = () => ({ status: 'ok' });
let responder = defaultResponder;

export function respondWith(fn) {
  responder = fn;
}

export function resetRequests() {
  requests.length = 0;
  responder = defaultResponder;
}

export default class AjaxRequest {
  constructor(url) {
    this.url = url;
    this.middlewares = [];
    this.aborted = false;
  }

  addMiddleware(middleware) {
    this.middlewares.push(middleware);
    return this;
  }

  abort() {
    this.aborted = true;
  }

  get() {
    return this.send('GET', undefined);
  }

  post(body) {
    return this.send('POST', body);
  }

  send(method, body) {
    const request = { url: this.url, method, body, middlewares: this.middlewares, instance: this };
    requests.push(request);
    return new Promise((resolve) => resolve(responder(request)))
      .then((data) => ({ resolve: async () => data }));
  }
}
