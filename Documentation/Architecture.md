# Architecture

Lite is the stable metadata and native-integration foundation. Pro adds real implementation code through ProOperationsInterface and a dependent TYPO3 package; missing Pro is rejected at the API boundary, not merely hidden in the browser.

Persistence13 and Persistence14 isolate the different native persistence signatures. Controller subclasses remain only where an after-success result is needed or the initial eager catalogue must be deferred. FAL lifecycle uses official PSR-14 events. Category writes use DataHandler.

The profile identity follows the persistence identifier; FormLifecycle migrates it on successful Core/FAL operations and purges it on file deletion or permanent database removal; soft-deleted database records retain metadata for restoration. Shared profiles are live-only for writes. Private-table schema is retained on downgrade to avoid silent data loss.

JavaScript and CSS are registered through TYPO3's asset/import-map mechanisms. Dynamic text uses XLF labels. No CDN or remote activation is required. ZIP export and validated transfer code are present only in Pro.
