# Changelog

## 1.0.2

- Centre the loading message horizontally and vertically in the available table area.
- Keep a single table loading row, including during rapid filter and sorting changes.

## 1.0.1

- Replace table rows with one centred loading row during AJAX requests.
- Remove the floating processing overlay and duplicate loading messages.
- Preserve the loading area during consecutive requests and restore rows afterwards.
- Normalize empty internal notes so Lite can save metadata for new forms.

## 1.0.0 — prepared release

- Use the `linkloot` Composer vendor for both packages and their dependency.
- Show locked Pro feature previews in Lite, with server-side access checks unchanged.
- Report missing database tables/columns with a setup hint instead of a generic list error.
- Support TYPO3 13.4 and 14.3; PHP 8.2 to 8.4 subject to Core requirements.
- Separate Lite base package and optional Pro add-on; reject Pro operations server-side without the add-on.
- Use native CSS sticky table headers and native Core action authorization.
- Preserve metadata across successful FAL moves/renames; clear stale metadata after deletion or identity reuse.
- Keep personal views workspace-scoped; explicitly disallow metadata writes outside the live workspace.
- Add XLF translations, standalone PHPUnit tests, PHPStan Level 5, TYPO3 coding standards and release packaging.
