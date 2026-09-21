<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/** Metadata follows successful Core/FAL operations; no changes to form definitions. */
final class FormLifecycle
{
    private const TABLES = ['tx_formmanagerplus_profile', 'tx_formmanagerplus_personal', 'tx_formmanagerplus_history'];
    public function __construct(private ConnectionPool $pool, private FormCatalog $catalog) {}

    /** Core database records may be soft-deleted and restored; their UID is not reused. */
    public function deleted(string $identifier): void
    {
        if (ctype_digit($identifier) && (new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() >= 14) {
            $query = $this->pool->getQueryBuilderForTable('form_definition');
            // Maintenance must include deleted rows; normal reads remain restricted.
            $query->getRestrictions()->removeAll();
            $exists = $query->select('uid')->from('form_definition')->where($query->expr()->eq('uid', $query->createNamedParameter((int)$identifier, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))->executeQuery()->fetchOne();
            if ($exists !== false) {
                $this->catalog->clear();
                return;
            }
        }
        $this->forget($identifier);
    }
    public function forget(string $identifier): void
    {
        foreach (self::TABLES as $table) {
            $this->pool->getConnectionForTable($table)->delete($table, ['form_key' => WorkspaceData::key($identifier)]);
        }
        $this->catalog->clear();
    }
    public function relocate(string $previous, string $current): void
    {
        if ($previous === $current) {
            return;
        }
        foreach (self::TABLES as $table) {
            $connection = $this->pool->getConnectionForTable($table);
            $connection->transactional(function () use ($connection, $table, $previous, $current): void {
                // A reused destination path must never attach another form's old metadata.
                $connection->delete($table, ['form_key' => WorkspaceData::key($current)]);
                $values = ['form_key' => WorkspaceData::key($current)];
                if ($table === 'tx_formmanagerplus_profile') {
                    $values['form_identifier'] = $current;
                }
                $connection->update($table, $values, ['form_key' => WorkspaceData::key($previous)]);
            });
        }
        $this->catalog->clear();
    }
}
