# Architecture

Agent-facing component map for **nr_passkeys_be**. Facts here are verified against the tree; when in doubt, the code and `Tests/Architecture/ArchitectureTest.php` win.

## System overview

The extension adds passwordless WebAuthn/FIDO2 backend login to TYPO3 (^12.4 || ^13.4 || ^14.3). Browser-side JS drives the WebAuthn ceremonies; PHP controllers exposed via backend AJAX routes validate them through service classes built on `web-auth/webauthn-lib`, persist credentials in a dedicated table, and enforce per-group adoption policies (Off → Encourage → Required → Enforced) via middleware, event listeners, and an admin module.

## Components

| Component | Path | Role |
|-----------|------|------|
| Authentication service | `Classes/Authentication/PasskeyAuthenticationService.php` | TYPO3 auth-chain service (priority 80); uses `GeneralUtility::makeInstance()` (no DI in the auth chain) |
| Controllers | `Classes/Controller/` | `LoginController` (public endpoints), `ManagementController` (own passkeys), `AdminController` (admin JSON API), `AdminModuleController` (backend module); shared `JsonBodyTrait`, `BackendUserTrait` |
| Services | `Classes/Service/` | `WebAuthnService`, `AttestationService` (registration), `AssertionService` (login), `WebAuthnCeremonyFactory`, `ChallengeService` (HMAC tokens), `CredentialRepository` (QueryBuilder CRUD), `RateLimiterService`, `EnforcementService`, `AdoptionStatsService`, `ExtensionConfigurationService` |
| Domain | `Classes/Domain/` | `Model/Credential` (plain PHP entity), typed DTOs in `Dto/`, enums `EnforcementLevel` and `CredentialDiscoverability` in `Enum/` |
| Middleware | `Classes/Middleware/` | `PasskeySetupInterstitial` (blocks navigation under Required), `PublicRouteResolver` (dispatches unauthenticated routes) |
| Event listeners | `Classes/EventListener/` | `InjectPasskeyLoginFields`, `InjectPasskeyBanner` (PSR-14, inject login/banner UI config) |
| UI components | `Classes/Form/Element/PasskeyInfoElement.php`, `Classes/UserSettings/PasskeySettingsPanel.php` | FormEngine element, user-settings panel (`callUserFunction`, no DI) |
| Dashboard widgets | `Classes/Widgets/` | Adoption widgets + data providers; registered on TYPO3 v14.3+ only via guarded `Configuration/Services.Dashboard.php` |
| CLI | `Classes/Command/RecoveryCommand.php` | Recovery command (Symfony Console) |
| Frontend | `Resources/Public/JavaScript/` | `PasskeyLogin`, `PasskeyManagement`, `PasskeyBanner`, `PasskeyDashboard`, `PasskeyAdminInfo` (+ `Util/Base64.js`) |
| Wiring | `Configuration/` | `Services.yaml`/`Services.php` (DI), `Backend/AjaxRoutes.php` + `Backend/Routes.php` (endpoints), `Backend/Modules.php`, TCA overrides |

## Dependency rules (enforced)

Enforced by phpat via `Tests/Architecture/ArchitectureTest.php` (runs inside `composer ci:test:php:phpstan`):

- Layering (inner → outer): Domain (Model + Dto) → Service → Controller / Authentication / Middleware / EventListener / UI.
- Domain must not depend on Controller, Middleware, Authentication, EventListener, UserSettings, Form, or Service.
- Configuration classes are pure value objects (no dependency on Service/Controller/Authentication/Middleware/EventListener/UserSettings/Form).
- Services must not depend on Controller, Middleware, EventListener, UserSettings, or Form.
- `Middleware\PublicRouteResolver` must not depend on Service, Domain, Authentication, or Controller (dispatch only).
- EventListener, Authentication, UserSettings, and Form must not depend on controllers (and the other HTTP/UI namespaces listed per rule).
- Finality: classes in Service, Controller, Domain\Dto, Domain\Model, Configuration, Middleware, EventListener, UserSettings, Authentication, and Form are `final`. Documented exceptions: `PasskeyAuthenticationService` (extends TYPO3 `AbstractAuthenticationService`) and `Form\Element\PasskeyInfoElement` (extends `AbstractFormElement`).

## Data flow

1. **Registration (attestation):** `PasskeyManagement.js` → AJAX route → `ManagementController` → `ChallengeService` (HMAC-signed, single-use challenge) → `AttestationService`/`WebAuthnService` → `CredentialRepository` persists the credential.
2. **Login (assertion):** `PasskeyLogin.js` → public route (`PublicRouteResolver`) → `LoginController` → `RateLimiterService` gate → `AssertionService`/`WebAuthnService` verify → `PasskeyAuthenticationService` completes TYPO3 backend auth at priority 80.
3. **Enforcement:** `EnforcementService` evaluates group level + grace period; `PasskeySetupInterstitial` middleware blocks navigation (Required), `InjectPasskeyBanner` nudges (Encourage), `AdminModuleController`/`AdoptionStatsService` report adoption.

## Key decisions

- ADRs live in `docs/adr/`: [0001 per-user password enforcement](adr/0001-per-user-password-enforcement.md), [0002 no rpId-aware enforcement](adr/0002-no-rpid-aware-enforcement.md).
- Security model and deployment requirements: `Documentation/Security/` (rendered docs are the authority for operators).
- Extension-level conventions and boundaries: root `AGENTS.md` and scoped `AGENTS.md` files.

## Terminology

| Term | Means |
|------|-------|
| Passkey | WebAuthn/FIDO2 credential (platform authenticator) |
| Assertion | Authentication ceremony (verifying a passkey) |
| Attestation | Registration ceremony (creating a passkey) |
| Challenge token | HMAC-signed, time-limited, single-use token for WebAuthn ceremonies |
| Lockout | Account lock after N failed auth attempts (per-IP and per-username) |
| Discoverable login | Login without entering username first (resident key) |
| Enforcement level | Per-group setting: Off, Encourage, Required, Enforced |
| Grace period | Days before Required enforcement becomes mandatory |
| Interstitial | Full-page setup prompt blocking navigation until passkey registered |
| Nudge | Admin-triggered reminder flag on a user's record |
