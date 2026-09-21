# Form Manager Plus Lite

TYPO3 form organization by scheffer-webdesign / LinkLoot. Version **1.0.0** for **TYPO3 13.4 and 14.3**, with **PHP 8.2 to 8.4** subject to the chosen Core version's requirements.

- Composer package: `scheffer-webdesign/form-manager-plus`
- Extension key: `form_manager_plus`
- Product: https://typo3.linkloot.io/
- Licence: GPL-2.0-or-later. See [LICENSE](LICENSE).

| Feature | Lite | Pro add-on |
|---|---|---|
| Search, sorting, pagination, storage/category filters | Yes | Yes |
| Categories, purpose, favourites and recent edits | Yes | Yes |
| Native create/edit/delete/reference actions | Yes | Yes |
| Copy categories and purpose when duplicating | Yes | Yes |
| Responsible user, person/team and internal notes | — | Yes |
| My forms and ownership/maintenance filters | — | Yes |
| Personal/team saved views, category appearance, change history | — | Yes |
| Bulk categories/ownership, ZIP export | — | Yes |
| Import and single-form export | — | Yes |

Pro is a separate extension requiring Lite. Installing Lite alone does not expose Pro API operations. Pro badges identify installed Pro features. No remote licence service, telemetry or online activation is used. Commercial distribution does not change the included GPL licence.

## Installation

For installation from the supplied ZIP archives, place the package folders under `packages/` in a TYPO3 project. Pro installations need **both** folders. Add the following path repository to the project's Composer configuration:

```json
{
  "repositories": [{
    "type": "path",
    "url": "packages/*",
    "options": {"versions": {
      "scheffer-webdesign/form-manager-plus": "1.0.0",
      "scheffer-webdesign/form-manager-plus-pro": "1.0.0"
    }}
  }]
}
```

Then run in the TYPO3 project:

```bash
composer require scheffer-webdesign/form-manager-plus:^1.0
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

For Classic mode, install the Lite ZIP first, then the Pro ZIP if required. Run TYPO3's database/schema update and flush all caches. Core `form` must be installed and its normal authorized storage locations configured. Pro ZIP export requires PHP `ext-zip`.

No demo users, automatic login, filemount grants or seeded forms are part of these packages.

## Permissions and configuration

Core module, FAL filemount, record, field and category permissions remain authoritative. Non-administrators may import only when their backend user/group TSconfig explicitly grants:

```typoscript
options.formManagerPlus.allowImport = 1
```

This grant does not bypass native storage permissions. Import/export and bulk operations require Pro on the server. Shared views never grant access to forms. Administrators choose groups; other users can share only with their own active groups.

Metadata changes and imports are restricted to the live workspace. Personal favourites, recent edits, saved views and history queries are workspace-scoped. Profiles describe the shared form, not a publishable workspace draft; the UI and API do not write live metadata from a non-live workspace.

## Data and upgrades

Lite owns four private tables: `tx_formmanagerplus_profile`, `tx_formmanagerplus_personal`, `tx_formmanagerplus_view`, and `tx_formmanagerplus_history`. The last two support Pro and remain intact when Pro is removed. Two `sys_category` appearance columns are shared storage; their editing UI requires Pro. FAL categories use native file metadata; TYPO3-14 database-form categories use the profile table.

Core/FAL rename and move events transfer metadata, favourites and history only after success. Deleted file forms and permanently removed database forms lose their associated metadata; reusing a file path must not inherit a deleted form's profile. Soft-deleted TYPO3-14 database forms retain metadata for native restoration. External filesystem changes that bypass TYPO3/FAL are not event-driven and require migration/cleanup as part of that external operation.

After upgrades run the normal TYPO3 schema update and cache flush. An upgrade from the earlier combined development package keeps the `form_manager_plus` key and all existing tables. Install the Pro add-on to retain its extended functionality. No uninstall operation silently purges the database.

## Behavior and limits

- The list uses a short-lived authorized metadata catalogue and server-side AJAX paging, bounded to 250 rows. File-form discovery and sorting still grow with the number of accessible forms; this is not a SQL-only index.
- Import is a three-stage AJAX flow. Overwrite matches the form's logical identifier, requires exactly one writable match, and checks for concurrent changes. New-form mode generates a new identity even if the source identifier exists.
- A transfer file contains a form definition, not submissions or metadata. Import validation intentionally rejects unsafe executable/configuration overrides and may reject unsupported custom options. Review the preview; import is not a complete site migration tool.
- Bulk actions select at most 50 forms, check every selected form before writes and report per-form concurrent failures. They are not a transaction spanning multiple storages. A ZIP holds individual `.fmp.json` files; extract them before import. Combined JSON is limited to 10 MB; a single import to 2 MB.
- Metadaten/Metadata shares the native Save button. Additional metadata requests run after Core saves the form; failures retain unsaved state and show an error. Form definition and metadata are not one cross-storage transaction.
- No automatic form deletion, finisher execution or message sending occurs.

## Integration

Core template overrides and a small version-specific FormManagerController subclass preserve native form actions and authorization. The subclass defers the initial catalogue, handles optional duplicate assignments and confirms successful create/delete results. Other extensions overriding the same controller/template must be tested together; this package does not replace Core files. Official events are used for FAL lifecycle and button integration.

Labels are XLF-based, with German and English shipped. JavaScript uses TYPO3-loaded labels and an English fallback. Assets are local; DataTables' MIT licence and pinned origin are included in Lite's `Resources/Public/Vendor/DataTables/`.

## Development and release

See [CONTRIBUTING.md](CONTRIBUTING.md), [RELEASE.md](RELEASE.md), [SECURITY.md](SECURITY.md) and [Documentation/Verification.md](Documentation/Verification.md).

```bash
composer install
composer test:unit
composer test:functional
composer check:types
composer check:style
composer release:check
composer release:build
```

The standalone PHPUnit suite uses TYPO3's testing framework. Legacy demo diagnostics are kept separately in Lite's `Tests/Manual`; they are not prerequisites for installing or testing the released package.
