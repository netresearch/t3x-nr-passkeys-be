# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |
| < latest | :x:               |

## Reporting a Vulnerability

1. **Do NOT open a public GitHub issue** for security vulnerabilities
2. Report via [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-passkeys-be/security/advisories/new)

### What to Include

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Response Timeline

- **Initial response**: Within 48 hours
- **Status update**: Within 7 days
- **Resolution target**: Within 30 days

## Security Measures

This project implements:

- **HMAC-SHA256** signed challenge tokens with constant-time comparison
- **Nonce-based replay protection** for authentication challenges
- **Rate limiting** per IP and per account lockout after failed attempts
- **User enumeration prevention** with randomized timing for unknown users
- **PHPStan level 8** static analysis
- **Mutation testing** to ensure test quality
- **Dependency scanning** via Dependabot

## Branch Protection

The `main` branch requires:

- Pull request with 1+ approvals
- Passing CI status checks
- No force pushes
