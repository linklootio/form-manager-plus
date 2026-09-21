# Changelog

## 1.0.0 — prepared release

- Support TYPO3 13.4 and 14.3; PHP 8.2 to 8.4 subject to Core requirements.
- Separate Lite base package and optional Pro add-on; reject Pro operations server-side without the add-on.
- Use native CSS sticky table headers and native Core action authorization.
- Preserve metadata across successful FAL moves/renames; clear stale metadata after deletion or identity reuse.
- Keep personal views workspace-scoped; explicitly disallow metadata writes outside the live workspace.
- Add XLF translations, standalone PHPUnit tests, PHPStan Level 5, TYPO3 coding standards and release packaging.
