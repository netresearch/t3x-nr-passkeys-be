# Agents

This file documents the AI coding agents that can work on this project and their capabilities.

## Project Context

**passkeys_be** is a TYPO3 extension providing passwordless backend authentication via
WebAuthn/FIDO2 Passkeys. See `CLAUDE.md` for full architecture and conventions.

## Available Agents

### Default Agent (Claude Code)

General-purpose coding agent for all tasks on this project.

**Capabilities:**
- Implement features across all layers (Service, Controller, Authentication, Domain)
- Write and run unit, functional, fuzz, and mutation tests
- Fix bugs and refactor code
- Update CI/CD configuration
- Perform code review

**Key files to understand before making changes:**
- `Classes/Service/WebAuthnService.php` - Core WebAuthn logic
- `Classes/Authentication/PasskeyAuthenticationService.php` - TYPO3 auth chain integration
- `Classes/Service/ChallengeService.php` - Challenge token security
- `Configuration/Services.yaml` - Dependency injection
- `ext_localconf.php` - Service registration and cache configuration

**Testing requirements:**
- All changes must pass PHPStan level 8
- All changes must pass php-cs-fixer (PER-CS2.0)
- Unit tests required for service and controller logic
- Functional tests required for database-dependent code
- Mutation testing MSI target: 80%

**Commands:**
```bash
composer ci:lint:php          # Code style check
composer ci:stan              # Static analysis
composer ci:test:php:unit     # Unit tests
composer ci:test:php:functional  # Functional tests
composer ci:mutation          # Mutation testing
```
