# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

**Do NOT open a public GitHub issue for security vulnerabilities.**

### How to Report

Use [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-passkeys-be/security/advisories/new) to report vulnerabilities privately.

### What to Include

- Description of the vulnerability
- Steps to reproduce (proof of concept if possible)
- Affected versions
- Potential impact assessment
- Suggested fix (if any)

### Response Timeline

| Severity | Initial Response | Fix Target   |
|----------|------------------|--------------|
| Critical | 48 hours         | 7 days       |
| High     | 5 business days  | 30 days      |
| Medium   | 10 business days | 90 days      |
| Low      | 14 business days | Next release |

### What to Expect

1. **Acknowledgment**: We will acknowledge receipt of your report within the timelines above
2. **Assessment**: We will investigate and assess the severity of the vulnerability
3. **Fix**: We will develop and test a fix
4. **Disclosure**: We will coordinate disclosure with you and publish a security advisory

### Scope

This policy covers the `nr_passkeys_be` TYPO3 extension code. For vulnerabilities in
dependencies (e.g., `web-auth/webauthn-lib`, TYPO3 core), please report to the respective
upstream projects.

## Security Measures

This project implements:

- **HMAC-SHA256** signed challenge tokens with constant-time comparison
- **Nonce-based replay protection** for authentication challenges
- **Rate limiting** per IP and per account lockout after failed attempts
- **User enumeration prevention** with randomized timing for unknown users
- **PHPStan level 10** static analysis with ergebnis/phpstan-rules
- **Mutation testing** to ensure test quality (MSI >= 60%, covered MSI >= 75%)
- **Fuzz testing** for challenge tokens, credential IDs, and request payloads
- **Dependency scanning** via Dependabot and `composer audit`
- **CodeQL** semantic security analysis
- **OpenSSF Scorecard** supply chain security monitoring

## Branch Protection

The `main` branch requires:

- Pull request with approving review
- Passing CI status checks (lint, PHPStan, unit, functional, fuzz, mutation)
- Signed commits
- No force pushes

## Safe Harbor

We consider security research conducted in good faith to be authorized. We will not pursue
legal action against researchers who follow responsible disclosure practices.
