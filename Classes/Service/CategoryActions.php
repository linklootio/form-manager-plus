<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/** Category controls retain Core FormEngine links and DataHandler persistence. */
final class CategoryActions
{
    public function __construct(private UriBuilder $uriBuilder, private ResourceFactory $resourceFactory) {}
    /** Read fresh FAL relations rather than the cached form catalogue. */
    public function fileAssignment(array $form, array $choices): array
    {
        $uid = (int)($form['fileUid'] ?? 0);
        if ($uid < 1) {
            return ['selected' => [], 'version' => '', 'editable' => false];
        }
        $file = $this->resourceFactory->getFileObject($uid);
        if (!$file->checkActionPermission('read')) {
            throw new \DomainException('access');
        }
        $metadata = $file->getMetaData()->get();
        $metadataUid = (int)($metadata['uid'] ?? 0);
        $pool = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $ids = array_map('intval', $pool->getConnectionForTable('sys_category_record_mm')->select(['uid_local'], 'sys_category_record_mm', ['uid_foreign' => $metadataUid, 'tablenames' => 'sys_file_metadata', 'fieldname' => 'categories'])->fetchFirstColumn());
        sort($ids);
        $user = $GLOBALS['BE_USER'];
        $editable = empty($form['readOnly']) && empty($form['invalid']) && $file->checkActionPermission('write') && $metadataUid > 0
            && $user->check('tables_modify', 'sys_file_metadata') && $user->check('non_exclude_fields', 'sys_file_metadata:categories')
            && $user->check('tables_select', 'sys_category') && $user->checkLanguageAccess(0)
            && $user->recordEditAccessInternals('sys_file_metadata', $metadata);
        return ['selected' => array_values(array_intersect($ids, array_map('intval', array_column($choices, 'uid')))), 'version' => hash('sha256', json_encode($ids)), 'editable' => $editable];
    }
    public function saveFileAssignment(array $form, array $choices, array $ids, string $version): array
    {
        if (count($ids) > 50) {
            throw new \InvalidArgumentException('input');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('input');
            }
        }
        $allowed = array_map('intval', array_column($choices, 'uid'));
        if (array_diff($ids, $allowed)) {
            throw new \DomainException('access');
        }
        $current = $this->fileAssignment($form, $choices);
        if (!$current['editable']) {
            throw new \DomainException('readonly');
        }
        if (!hash_equals($current['version'], $version)) {
            throw new \DomainException('conflict');
        }
        $file = $this->resourceFactory->getFileObject((int)$form['fileUid']);
        $metadataUid = (int)$file->getMetaData()->get()['uid'];
        $pool = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $existing = array_map('intval', $pool->getConnectionForTable('sys_category_record_mm')->select(['uid_local'], 'sys_category_record_mm', ['uid_foreign' => $metadataUid, 'tablenames' => 'sys_file_metadata', 'fieldname' => 'categories'])->fetchFirstColumn());
        // Keep assignments outside the current editor's visible category choices.
        $ids = array_values(array_unique(array_merge($ids, array_diff($existing, $allowed))));
        $handler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $handler->start(['sys_file_metadata' => [$metadataUid => ['categories' => implode(',', $ids)]]], []);
        $handler->process_datamap();
        if ($handler->errorLog !== []) {
            throw new \DomainException('access');
        }
        return $this->fileAssignment($form, $choices);
    }
    public function canRename(int $uid): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user || !$user->check('tables_modify', 'sys_category')) {
            return false;
        }
        $record = BackendUtility::getRecord('sys_category', $uid);
        return $record && $user->recordEditAccessInternals('sys_category', $record)
            && ($user->isAdmin() || (bool)BackendUtility::readPageAccess((int)$record['pid'], $user->getPagePermsClause(16)));
    }
    public function rename(int $uid, string $title, string $previous): string
    {
        $title = trim($title);
        if ($uid < 1 || $title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('input');
        }
        if (!$this->canRename($uid)) {
            throw new \DomainException('access');
        }
        $record = BackendUtility::getRecord('sys_category', $uid);
        if ($record['title'] !== $previous) {
            throw new \DomainException('conflict');
        }
        $handler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $handler->start(['sys_category' => [$uid => ['title' => $title]]], []);
        $handler->process_datamap();
        if ($handler->errorLog !== []) {
            throw new \DomainException('access');
        }
        $saved = BackendUtility::getRecord('sys_category', $uid);
        if (($saved['title'] ?? '') !== $title) {
            throw new \DomainException('access');
        }
        return $title;
    }
    public function appearance(int $uid, string $icon, string $color): array
    {
        if (!in_array($icon, CategoryStyle::ICONS, true) || ($color !== '' && !preg_match('/^#[a-f0-9]{6}$/iD', $color))) {
            throw new \InvalidArgumentException('input');
        }
        if ($uid < 1 || !$this->canStyle($uid)) {
            throw new \DomainException('access');
        }
        $handler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $handler->start(['sys_category' => [$uid => ['tx_fmp_icon' => $icon, 'tx_fmp_color' => $color]]], []);
        $handler->process_datamap();
        if ($handler->errorLog !== []) {
            throw new \DomainException('access');
        }
        return CategoryStyle::item(BackendUtility::getRecord('sys_category', $uid));
    }
    public function canStyle(int $uid): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user || !$user->check('tables_modify', 'sys_category')
            || !$user->check('non_exclude_fields', 'sys_category:tx_fmp_icon')
            || !$user->check('non_exclude_fields', 'sys_category:tx_fmp_color')) {
            return false;
        }
        return $this->canRename($uid);
    }
    public function appearanceUrl(int $uid): string
    {
        if (!$this->canStyle($uid)) {
            return '';
        }
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_category' => [$uid => 'edit']],
            'columnsOnly' => ['sys_category' => 'tx_fmp_icon,tx_fmp_color'],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute((new Typo3Version())->getMajorVersion() >= 14 ? 'form_manager' : 'web_FormFormbuilder'),
        ]);
    }

    public function editUrl(int $fileUid, bool $editable, string $returnTo, string $formIdentifier): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $database = $fileUid < 1 && ctype_digit($formIdentifier) && (new Typo3Version())->getMajorVersion() >= 14;
        if ($database) {
            if (!$editable || !$user || !$user->check('tables_modify', 'form_definition') || !$user->check('tables_select', 'sys_category')) {
                return '';
            }
            return (string)$this->uriBuilder->buildUriFromRoute('form_manager_plus_tools', ['form' => $formIdentifier, 'tab' => 'categories']);
        }
        $table = 'sys_file_metadata';
        $field = 'categories';
        if (!$editable || ($fileUid < 1) || !$user
            || !$user->check('tables_modify', $table)
            || !$user->check('non_exclude_fields', $table . ':' . $field)
            || !$user->checkLanguageAccess(0)) {
            return '';
        }
        try {
            {
                $file = $this->resourceFactory->getFileObject($fileUid);
                if (!$file->checkActionPermission('read') || !$file->checkActionPermission('write')) {
                    return '';
                }
                $metadataUid = (int)($file->getMetaData()->get()['uid'] ?? 0);
            }
            if ($metadataUid < 1) {
                return '';
            }
            return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$metadataUid => 'edit']],
                'columnsOnly' => [$table => $field],
                'returnUrl' => $returnTo,
                'fmpForm' => $formIdentifier,
            ]);
        } catch (\TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException) {
            return '';
        }
    }

    /** @return list<array{title: string, url: string}> */
    public function creationTargets(string $returnTo): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user || !$user->check('tables_modify', 'sys_category')) {
            return [];
        }
        $targets = [];
        $mounts = (new Typo3Version())->getMajorVersion() >= 14 ? $user->getWebmounts() : $user->returnWebmounts();
        foreach ($mounts as $mount) {
            $pid = (int)$mount;
            $page = BackendUtility::readPageAccess($pid, $user->getPagePermsClause(8));
            if (!$page && !($pid === 0 && $user->isAdmin())) {
                continue;
            }
            $targets[] = [
                'title' => (string)($page['title'] ?? 'TYPO3'),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['sys_category' => [$pid => 'new']],
                    'columnsOnly' => ['sys_category' => 'title,parent,description,tx_fmp_icon,tx_fmp_color'],
                    'returnUrl' => $returnTo,
                ]),
            ];
        }
        return $targets;
    }
}
