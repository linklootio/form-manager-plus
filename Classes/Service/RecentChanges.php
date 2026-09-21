<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class RecentChanges
{
    private array $categoryBefore = [];
    public function __construct(private WorkspaceData $data, private ConnectionPool $pool, private FormCatalog $catalog, private FormLifecycle $lifecycle) {}
    private function assignment(int $uid): array
    {
        return array_map('intval', $this->pool->getConnectionForTable('sys_category_record_mm')->select(['uid_local'], 'sys_category_record_mm', ['uid_foreign' => $uid, 'tablenames' => 'sys_file_metadata', 'fieldname' => 'categories'])->fetchFirstColumn());
    }
    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $handler): void
    {
        if ($table === 'form_definition') {
            $this->catalog->clear();
        }
        if ($command === 'delete' && $table === 'form_definition' && is_numeric($id)) {
            $row = $this->pool->getConnectionForTable($table)->select(['deleted'], $table, ['uid' => (int)$id])->fetchAssociative();
            if (!$row || !empty($row['deleted'])) {
                $this->lifecycle->deleted((string)$id);
            }
        }
    }
    public function processDatamap_preProcessFieldArray(array &$fields, string $table, int|string $id, DataHandler $handler): void
    {
        if (PHP_SAPI !== 'cli' && $table === 'sys_file_metadata' && is_numeric($id) && array_key_exists('categories', $fields)) {
            $this->categoryBefore[(int)$id] = $this->assignment((int)$id);
        }
    }
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fields, DataHandler $handler): void
    {
        if (in_array($table, ['sys_category', 'sys_file_metadata', 'form_definition'], true)) {
            $this->catalog->clear();
        }
        if (PHP_SAPI === 'cli' || empty($GLOBALS['BE_USER']->user['uid'])) {
            return;
        }
        $uid = is_numeric($id) ? (int)$id : (int)($handler->substNEWwithIDs[$id] ?? 0);
        if (!$uid) {
            return;
        }
        if ($table === 'form_definition') {
            $this->data->edited((string)$uid);
        }
        if ($table === 'sys_file_metadata' && array_key_exists('categories', $fields)) {
            $db = $this->pool->getConnectionForTable('sys_file_metadata');
            $file = $db->fetchAssociative('SELECT f.storage, f.identifier FROM sys_file f INNER JOIN sys_file_metadata m ON m.file=f.uid WHERE m.uid=?', [$uid]);
            if ($file && str_ends_with($file['identifier'], '.form.yaml')) {
                $identifier = $file['storage'] . ':' . $file['identifier'];
                $this->data->edited($identifier);
                if (isset($this->categoryBefore[$uid])) {
                    $this->data->recordHistory($identifier, ['categories' => $this->categoryBefore[$uid]], ['categories' => $this->assignment($uid)]);
                }
            }
            unset($this->categoryBefore[$uid]);
        }
    }
}
