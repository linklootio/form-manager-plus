<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Form\Service\DatabaseService;

/** Short-lived, user/permission-scoped metadata catalogue. Never caches tokens. */
final class FormCatalog
{
    private bool $cacheHit = false;
    public function __construct(private FormSourceInterface $source, private CategoryProvider $categories, private DatabaseService $references, private CacheManager $cacheManager) {}
    public function forms(bool $refresh = false): array
    {
        $user = $GLOBALS['BE_USER'];
        $identity = array_intersect_key($user->user, array_flip(['uid', 'admin', 'usergroup', 'allowed_languages', 'file_permissions', 'workspace_perms', 'TSconfig', 'lang']));
        $key = hash('sha256', serialize(['v2-category-styles', $identity, $user->groupData, $user->getTSConfig(), $user->workspace,
            \TYPO3\CMS\Backend\Utility\BackendUtility::getPagesTSconfig(0),
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['form'] ?? [], (new Typo3Version())->getVersion()]));
        $cache = $this->cacheManager->getCache('form_manager_plus_catalog');
        if (!$refresh && ($forms = $cache->get($key)) !== false) {
            $this->cacheHit = true;
            return $forms;
        }
        $this->cacheHit = false;
        $forms = $this->source->listForms();
        $groups = [];
        foreach (array_chunk(array_column($forms, 'fileUid'), 500) as $ids) {
            $groups += $this->categories->itemsForFiles(array_map('intval', $ids));
        }
        $legacy = (new Typo3Version())->getMajorVersion() < 14;
        $databaseGroups = [];
        if (!$legacy) {
            $databaseIds = array_filter(array_column($forms, 'persistenceIdentifier'), static fn($id): bool => ctype_digit((string)$id));
            foreach (array_chunk($databaseIds, 500) as $ids) {
                $databaseGroups += $this->categories->itemsForDatabaseForms($ids);
            }
        }
        $files = $legacy ? $this->references->getAllReferencesForFileUid() : [];
        $paths = $legacy ? $this->references->getAllReferencesForPersistenceIdentifier() : [];
        foreach ($forms as &$form) {
            $uid = (int)($form['fileUid'] ?? 0);
            $form['fileUid'] = $uid;
            $form['categoryItems'] = $uid ? ($groups[$uid] ?? []) : ($databaseGroups[(int)$form['persistenceIdentifier']] ?? []);
            $form['group'] = CategoryStyle::title($form['categoryItems']);
            if ($legacy) {
                $form['referenceCount'] = (int)($files[$uid] ?? $paths[$form['persistenceIdentifier']] ?? 0);
            }
            unset($form['editUrl'], $form['historyUrl']);
        }
        unset($form);
        $cache->set($key, $forms, [], 30);
        return $forms;
    }
    public function clear(): void
    {
        $this->cacheManager->getCache('form_manager_plus_catalog')->flush();
    }
    public function wasCacheHit(): bool
    {
        return $this->cacheHit;
    }
}
