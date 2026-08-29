<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md -- Resources

## Overview
Fluid templates, XLIFF translations, JavaScript, and icons for the passkeys backend extension.

## Key Files
| File | Purpose |
|------|---------|
| `Private/Language/locallang.xlf` | UI labels (login, management, banner, interstitial) |
| `Private/Language/locallang_module.xlf` | Admin module labels (enforcement, adoption, dashboard) |
| `Private/Language/locallang_db.xlf` | TCA/database field labels |
| `Private/Language/locallang_dashboard.xlf` | Dashboard widget labels |
| `Private/Language/locallang_csh_be_users.xlf` | Context-sensitive help for be_users fields |
| `Private/Templates/AdminModule/Dashboard.html` | Admin dashboard with adoption stats |
| `Private/Templates/AdminModule/Help.html` | Admin module help (rollout guide, FAQ) |
| `Private/Templates/Interstitial/Setup.html` | Passkey setup interstitial page |
| `Private/Templates/UserSettings/Passkeys.html` | User settings passkey management panel |
| `Public/JavaScript/PasskeyLogin.js` | WebAuthn login ceremony (browser API calls) |
| `Public/JavaScript/PasskeyManagement.js` | Passkey registration/management UI logic |
| `Public/JavaScript/PasskeyBanner.js` | Encourage-stage dismissible banner (v12-v14 compat) |
| `Public/JavaScript/PasskeyDashboard.js` | Admin dashboard enforcement/adoption UI |
| `Public/JavaScript/PasskeyAdminInfo.js` | Admin info element display logic |
| `Public/Icons/Extension.svg` | Extension icon for TYPO3 backend |
| `Public/Icons/credential.svg` | Credential/passkey icon |

## Structure
```
Resources/
  Private/
    Language/          # XLIFF translation files (locallang*.xlf)
    Templates/
      AdminModule/     # Dashboard.html, Help.html
      Interstitial/    # Setup.html (passkey setup prompt)
      UserSettings/    # Passkeys.html (user settings panel)
  Public/
    Icons/             # SVG icons
    JavaScript/        # JS modules for WebAuthn + onboarding UX (+ Util/ helpers)
    Css/               # backend.css (theme-aware banner + login styles)
```

## Setup
- No build step: JS ships as native ES modules, CSS is plain -- edit files in place
- JS behavior is covered by Vitest tests in `Tests/JavaScript/` (`npm ci` once, then `npm run test:js`)

## Build & tests
- JS unit tests: `npm run test:js` (jsdom); coverage: `npm run test:js:coverage`
- Template/JS changes that affect login or banner flows: run `Build/Scripts/runTests.sh -s e2e`
- XLIFF changes: keep IDs in sync across all `locallang*.xlf` consumers (Fluid + PHP `TranslationTrait`)

## Conventions
- XLIFF files use `locallang*.xlf` naming
- Passkey login config comes from `InjectPasskeyLoginFields` event listener via inline JS
- Banner config comes from `InjectPasskeyBanner` event listener via inline JS
- JavaScript uses browser WebAuthn API (`navigator.credentials.create/get`)
- JS modules use TYPO3 `@typo3/backend/` imports for Modal, Notification, etc.
- Icons are SVG format (v14 three-color style: currentColor + 40% detail + `var(--nr-icon-accent, #2F99A4)`; `.legacy` teal-tile variant for v12/v13)
- `Public/Css/backend.css` holds theme-aware styles for banner + login divider (colors via core callout classes / currentColor, never hardcoded)
- V14 DOM: uses web components (`typo3-backend-module-router`) instead of `.scaffold-content-module`
- Banner container detection uses fallback chain for v12/v13/v14 compatibility

## Security
- Never inline secrets or credentials in templates or JS; WebAuthn config reaches JS only via the event-listener-injected inline config
- Escape all dynamic output in Fluid (default escaping stays ON; no `escapeOutput=false` without review)
- JS must treat server JSON as untrusted: no `innerHTML` from response data

## PR checklist
- [ ] `npm run test:js` passes
- [ ] New UI strings added to the right `locallang*.xlf` file (no hardcoded labels)
- [ ] Templates render on TYPO3 ^12.4, ^13.4, and ^14.3 (v14 DOM differs -- see Conventions)

## Examples (golden samples)
| Pattern | Reference |
|---------|-----------|
| JS module (v12-v14 compat) | `Public/JavaScript/PasskeyBanner.js` |
| JS test | `Tests/JavaScript/PasskeyBanner.test.js` (repo root) |
| Fluid template | `Private/Templates/UserSettings/Passkeys.html` |

## When stuck
- Banner/container detection across TYPO3 versions: fallback chain in `PasskeyBanner.js`
- Icon style rules: see Conventions (v14 three-color style vs `.legacy` variant)
- Root `AGENTS.md` for project-wide commands and rules
