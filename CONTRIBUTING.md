# Contributing to nr_passkeys_be

Thank you for considering contributing to the TYPO3 Passkeys Backend Authentication extension.

## Getting Started

1. Fork the repository
2. Clone your fork and create a feature branch
3. Install dependencies: `composer install`
4. Make your changes
5. Run quality checks (see below)
6. Submit a pull request

## Development Setup

```bash
composer install

# Verify everything works
composer ci:lint:php          # Code style (PER-CS2.0)
composer ci:stan              # PHPStan level 8
composer ci:test:php:unit     # Unit tests
```

## Quality Requirements

All contributions must pass the following quality gates:

| Check | Command | Requirement |
|-------|---------|-------------|
| Code style | `composer ci:lint:php` | PER-CS2.0 compliance |
| Static analysis | `composer ci:stan` | PHPStan level 8 |
| Unit tests | `composer ci:test:php:unit` | All tests pass |
| Mutation tests | `composer ci:mutation` | MSI >= 60%, covered MSI >= 75% |

### Writing Tests

- New features must include unit tests
- Bug fixes should include a regression test
- Functional tests require MySQL (run in CI only)
- Use `declare(strict_types=1)` in all PHP files

### Code Style

This project uses PHP-CS-Fixer with PER-CS2.0 coding standard. Fix style issues automatically:

```bash
composer ci:lint:php:fix
```

## Pull Request Process

1. Ensure all CI checks pass
2. Update documentation if applicable
3. Keep commits atomic -- one logical change per commit
4. Use conventional commit format: `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`
5. Sign your commits with GPG/SSH (`git commit -S`)

## Reporting Issues

- **Bugs**: Use the [bug report template](https://github.com/netresearch/t3x-nr-passkeys-be/issues/new?template=bug_report.md)
- **Features**: Use the [feature request template](https://github.com/netresearch/t3x-nr-passkeys-be/issues/new?template=feature_request.md)
- **Security**: See [SECURITY.md](SECURITY.md) for responsible disclosure

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.
