Operations and limitations
==========================

Data lifecycle
--------------

Four private tables store shared profiles, personal state, saved views and history.
File categories remain native FAL relations; database-form category assignments
use the profile table. Installing or removing Pro does not purge stored metadata.

Successful FAL renames/moves migrate associated metadata. File deletion and permanent database removal
clear it, preventing a reused identity from inheriting stale data. Soft-deleted
database forms retain metadata for native restoration. Filesystem
operations outside TYPO3/FAL must migrate metadata explicitly.

Workspaces
----------

Shared profiles are not workspace-versioned records. To prevent accidental live
changes, metadata writes and import are denied outside the live workspace.
Personal state, view visibility and history queries are workspace-scoped.

Saving and concurrency
----------------------

Core saves the definition first; metadata requests follow and report failures
without discarding unsaved metadata. Revisions protect metadata against concurrent
updates. Bulk changes are not one transaction across files and database records.
Keep normal backups before updating or replacing form definitions.

Compatibility
-------------

The list uses an authorized, short-lived catalogue, not an unlimited SQL index.
Cold discovery and sorting scale with the accessible form count. Native Core
controller/template overrides by other extensions can conflict and require
integration testing. No Core files are patched.

Release and support
-------------------

Run the supplied tests and artifact checks before tagging a release. Review the
verification record and RELEASE.md. Confirm repository and TER-key ownership,
enable private security reporting, then publish deliberately. No publication is
performed by the provided artifact workflow.
