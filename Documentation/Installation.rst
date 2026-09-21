Installation
============

Composer
--------

Make the package available through a Composer path repository before public
publication, as shown in the README. Pro requires both package folders.

.. code-block:: bash

   composer require linkloot/form-manager-plus:^1.0
   vendor/bin/typo3 extension:setup
   vendor/bin/typo3 cache:flush

Classic mode
------------

Install Lite first through the Extension Manager, then Pro when needed. Apply
TYPO3's database schema update and flush all caches. The extensions provide
TYPO3 14 Classic-mode Composer metadata, with no separately bundled PHP libraries.
Core form configuration and authorized storage locations must already exist.

Permissions
-----------

Native module, filemount, file, record and field permissions apply. In Pro,
non-administrators need the following user/group TSconfig to import:

.. code-block:: typoscript

   options.formManagerPlus.allowImport = 1

This setting does not grant access to additional storage locations. Editing
category appearance needs Pro and the corresponding native category-field rights.
No demo accounts, sample data or permission grants are installed.
