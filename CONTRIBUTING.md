# Contributing

Run `composer install` in this package, then `composer test:unit`, `composer test:functional`, `composer check:types`, `composer check:style` and `composer release:check`.
For Pro, first make Lite available through a local Composer path repository as described in the README.
Functional tests use TYPO3's testing framework and create their own isolated database; never point test variables at a customer database.

Test against both supported Core versions. Use `Build/Dockerfile` for a standalone PHP test runtime or the included GitHub Actions matrix. Add regression tests for permission, persistence, data-loss or edition-boundary changes.
Use XLF labels, native icons and supported Core APIs. Keep demo infrastructure outside these packages. Avoid unrelated refactoring, runtime CDN dependencies and changes to Core files.

Submit a focused change with a problem description, resulting behavior and verification. Security reports belong in the private channel documented in SECURITY.md.

## Standalone version selection

Use `composer update --with "typo3/cms-core:~13.4.0"` or the corresponding `~14.3.0` constraint to select a Core line without changing the supported range in the manifest.
For a Pro checkout next to the Lite checkout, configure its dependency before installation:

```bash
composer config repositories.lite '{"type":"path","url":"../form_manager_plus","options":{"versions":{"linkloot/form-manager-plus":"1.0.1"}}}'
```

A Docker runtime can be built with `docker build -t fmp-tests -f Build/Dockerfile .`.
Mount the checkout at `/work` and run the same Composer commands there. When using sibling Lite/Pro checkouts, mount their parent directory and choose the appropriate working directory so both packages remain accessible.

The default functional database is SQLite. For a disposable MariaDB database set `typo3DatabaseDriver=pdo_mysql`, `typo3DatabaseHost`, `typo3DatabaseUsername`, `typo3DatabasePassword`, and `typo3DatabaseName`. Never reuse a production database or account.
