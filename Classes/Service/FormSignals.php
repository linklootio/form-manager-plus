<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FormSignals
{
    public function __construct(private ConnectionPool $pool) {}
    public function enrich(array $forms): array
    {
        $files = $database = [];
        foreach ($forms as $form) {
            if (!empty($form['fileUid'])) {
                $files[] = (int)$form['fileUid'];
            } elseif (ctype_digit($form['persistenceIdentifier'])) {
                $database[] = (int)$form['persistenceIdentifier'];
            }
        }
        $fileDates = $this->dates('sys_file', 'modification_date', $files);
        $databaseDates = (new Typo3Version())->getMajorVersion() >= 14 ? $this->dates('form_definition', 'tstamp', $database) : [];
        foreach ($forms as &$form) {
            $identifier = $form['persistenceIdentifier'];
            $form['modifiedAt'] = !empty($form['fileUid']) ? ($fileDates[(int)$form['fileUid']] ?? 0) : ($databaseDates[(int)$identifier] ?? 0);
            if (str_starts_with($identifier, 'EXT:')) {
                $path = GeneralUtility::getFileAbsFileName($identifier);
                if ($path && is_file($path)) {
                    $form['modifiedAt'] = (int)filemtime($path);
                }
            }
        }
        unset($form);
        return $forms;
    }
    private function dates(string $table, string $field, array $ids): array
    {
        $dates = [];
        foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
            $q = $this->pool->getQueryBuilderForTable($table);
            foreach ($q->select('uid', $field)->from($table)->where($q->expr()->in('uid', $q->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
                $dates[(int)$row['uid']] = (int)$row[$field];
            }
        }
        return $dates;
    }
}
