# Release procedure

1. Confirm ownership of the Composer vendor and TER extension key; do not assume availability from a search result.
2. Publish the source to the intended repository only after review. Set the repository URL in Composer `support.source` and `support.issues`; no unverified repository URL is embedded in this prepared source.
3. Enable private vulnerability reporting, branch protection and required CI checks. For Pro CI, set `FMP_LITE_REPOSITORY` to the Lite repository slug and `LITE_READ_TOKEN` only if that repository is private.
4. Run the CI matrix (TYPO3 13.4/14.3, PHP 8.2/8.4, SQLite/MariaDB), browser acceptance and the artifact checks. Review Documentation/Verification.md and record any further environment verification.
5. Keep `extra.typo3/cms.version`, `ext_emconf.php`, changelog and intended Git tag synchronized. The prepared package state is stable; publication and repository ownership still need to be confirmed before creating the public release.
6. Run `composer release:build`. Inspect the ZIP and its SHA-256 manifest. Lite is the TER candidate; Pro is a separate add-on archive requiring Lite. Never upload the Pro archive to the Lite TER entry.
7. Create the matching Git tag and GitHub release, register/update Packagist, then upload the approved Lite archive to TER. These publication actions are intentionally not executed by the included artifact workflow.
8. Connect docs.typo3.org rendering with the repository webhook and verify the published documentation/version links.

## Classic mode

Install Lite first and then Pro through the Extension Manager. Update the database schema and flush all caches, including Extbase reflection metadata. Composer metadata includes the TYPO3 14 Classic-mode version and providesPackages declaration; no third-party Composer libraries are bundled separately by these extensions.

## Upgrades and removal

Back up the database and form files. Run TYPO3's normal extension setup/schema update and cache flush. The package split retains the existing Lite extension key and table names; installing Pro reuses the same metadata. Removing Pro preserves its stored data. Uninstalling either package does not silently purge customer data; review obsolete tables/fields explicitly in TYPO3's schema analyzer.
