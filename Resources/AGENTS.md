<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-02-09 -->

# AGENTS.md -- Resources

## Overview
Fluid templates, XLIFF translations, JavaScript, and icons for the passkeys backend extension.

## Key Files
| File | Purpose |
|------|---------|
| `Private/Language/locallang.xlf` | Frontend labels (login form, management UI) |
| `Private/Language/locallang_db.xlf` | TCA/database field labels |
| (none) | Passkey login UI is injected via JS into standard TYPO3 login form |
| `Private/Templates/UserSettings/Passkeys.html` | User settings passkey management panel |
| `Public/JavaScript/PasskeyLogin.js` | WebAuthn login ceremony (browser API calls) |
| `Public/JavaScript/PasskeyManagement.js` | Passkey registration/management UI logic |
| `Public/Icons/Extension.svg` | Extension icon for TYPO3 backend |
| `Public/Icons/credential.svg` | Credential/passkey icon |

## Structure
```
Resources/
  Private/
    Language/          # XLIFF translation files
    Layouts/           # Fluid layouts (currently empty)
    Partials/          # Fluid partials (currently empty)
    Templates/
      UserSettings/    # User settings module templates
  Public/
    Icons/             # SVG icons
    JavaScript/        # Frontend JS for WebAuthn API
```

## Conventions
- XLIFF files use `locallang*.xlf` naming
- Passkey login config comes from `InjectPasskeyLoginFields` event listener via inline JS
- JavaScript uses browser WebAuthn API (`navigator.credentials.create/get`)
- Icons are SVG format
- No CSS files -- uses TYPO3 backend default styling
