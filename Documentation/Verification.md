# Release verification — 2026-09-21

Prepared packages: Form Manager Plus Lite (`form_manager_plus`) and the dependent
Pro add-on (`form_manager_plus_pro`), version 1.0.0. No public release, Git tag,
TER upload or Packagist registration has been performed.

## Scope

The standalone suite uses TYPO3 testing-framework 9.7 and PHPUnit 11. It creates
its own test database, without demo users, visitor middleware or a running website.
Both editions are exercised on TYPO3 13.4.35 and 14.3.7, PHP 8.2.33 and 8.4.25,
with SQLite and MariaDB 11.4. Test coverage includes fresh schema/DI, the server-side
edition boundary, native Classic-mode package metadata loading, confirmed FAL
file/folder lifecycle events, stale destination cleanup, optimistic profile writes
and rejection of shared-profile writes outside the live workspace. Soft-deleted database
forms retain metadata for restoration; permanently removed records are cleaned.
The database-storage-specific restoration test is intentionally skipped on TYPO3 13. Pro's pure
validation tests additionally reject unsafe definitions and sanitize disclosed HTML.

PHPStan Level 5 uses version-specific configurations for Core's incompatible
controller/persistence signatures. TYPO3 coding standards and JavaScript syntax
checks run separately. XLF key parity and required release files are checked by
Build/check-release.php. The artifact builder keeps application/demo configuration,
credentials, dependencies, caches and test data out of installation archives.

## Additional integration evidence

The existing isolated development-installation diagnostics in Tests/Manual cover
native export/import round trips, overwrite-by-identifier, duplicate identifiers,
stale preview rejection, CSRF, category DataHandler writes, permissions, team view
ownership, personal/workspace separation, bulk preflight and valid ZIP downloads.
These diagnostics are supplementary and intentionally separate from portable CI.

Browser acceptance covers the real Lite-only interface and Pro interface, native
Save integration, localized metadata tabs, Pro AJAX import, and the unchanged
Core/Plus comparison. Demo infrastructure is not included in either release ZIP.

## Dependency status

Composer security audits found no vulnerability advisories in the tested resolutions.
TYPO3 13 still brings the abandoned upstream package doctrine/annotations; it is
reported explicitly. CI uses --abandoned=report while still failing on security
advisories. No advisory blocking was disabled to install dependencies.

## Publication boundaries

Classic-mode metadata is tested through Core's actual package reader. Public TER
and Packagist installation cannot be claimed before these unpublished packages are
registered. Confirm extension-key/vendor ownership and real repository URLs, enable
private reporting and connect documentation rendering before publishing.

No test proves compatibility with every third-party XCLASS/template override or
custom form configuration. Live-workspace-only metadata writes, external filesystem
changes outside FAL, import-option restrictions, and non-atomic cross-storage saves
are documented in the README and manual.

## Final matrix

Both editions passed the SQLite/MariaDB combinations on PHP 8.2.33 and 8.4.25
for TYPO3 13.4.35 and 14.3.7 (16 edition/Core/PHP/database combinations).
TYPO3-14 Lite runs 11 tests / 30 assertions; Pro runs 12 / 36 when including
unit tests. Functional-only MariaDB runs have 9 tests / 26 assertions.
TYPO3 13 skips only the database-form restoration case, which does not apply there.
Release ZIP rebuilds are byte-identical and include SHA-256 manifests.
