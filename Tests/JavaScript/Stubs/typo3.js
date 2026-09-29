/**
 * Stand-in for the TYPO3 core ES modules (@typo3/*) that the backend's import
 * map provides at runtime. Tests that import a shipped module which pulls in
 * core modules resolve every @typo3/* specifier here (see vitest.config.mjs).
 * DocumentService.ready() never resolves, so a module's constructor does not
 * start talking to the backend; the test drives the method it covers itself.
 */
export default { ready: () => new Promise(() => {}) };
export const SeverityEnum = {};
export const sudoModeInterceptor = {};
