<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-02 -->

# AGENTS.md -- Resources

## Overview
Fluid templates, XLIFF translations, JavaScript, and icons for the passkeys backend extension.

## Key Files
| File | Purpose |
|------|---------|
| `Private/Language/locallang.xlf` | UI labels (login, management, banner, interstitial) |
| `Private/Language/locallang_module.xlf` | Admin module labels (enforcement, adoption, dashboard) |
| `Private/Language/locallang_db.xlf` | TCA/database field labels |
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
    Language/          # 4 XLIFF files, 153 translation units total
    Layouts/           # Fluid layouts
    Partials/          # Fluid partials
    Templates/
      AdminModule/     # Dashboard.html, Help.html
      Interstitial/    # Setup.html (passkey setup prompt)
      UserSettings/    # Passkeys.html (user settings panel)
  Public/
    Icons/             # SVG icons
    JavaScript/        # 5 JS modules for WebAuthn + onboarding UX
```

## Conventions
- XLIFF files use `locallang*.xlf` naming
- Passkey login config comes from `InjectPasskeyLoginFields` event listener via inline JS
- Banner config comes from `InjectPasskeyBanner` event listener via inline JS
- JavaScript uses browser WebAuthn API (`navigator.credentials.create/get`)
- JS modules use TYPO3 `@typo3/backend/` imports for Modal, Notification, etc.
- Icons are SVG format
- No CSS files -- uses TYPO3 backend default styling + inline styles for banner
- V14 DOM: uses web components (`typo3-backend-module-router`) instead of `.scaffold-content-module`
- Banner container detection uses fallback chain for v12/v13/v14 compatibility
