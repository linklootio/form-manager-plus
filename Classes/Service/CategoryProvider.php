<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Reads standard category relations and optional Form Manager Plus appearance. */
final class CategoryProvider
{
    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * Restricts the query to files already authorized by the Form Framework.
     * Multiple categories become one deterministic group; no form is duplicated.
     *
     * @param list<int> $fileUids
     * @return array<int, string>
     */
    public function forFiles(array $fileUids): array
    {
        return array_map(CategoryStyle::title(...), $this->itemsForFiles($fileUids));
    }
    public function itemsForFiles(array $fileUids): array
    {
        $fileUids = array_values(array_unique(array_filter($fileUids, static fn(int $uid): bool => $uid > 0)));
        if ($fileUids === []) {
            return [];
        }
        $query = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $rows = $query->select('metadata.file', 'category.uid', 'category.title', 'category.tx_fmp_icon', 'category.tx_fmp_color')
            ->from('sys_file_metadata', 'metadata')
            ->join(
                'metadata',
                'sys_category_record_mm',
                'relation',
                $query->expr()->eq('relation.uid_foreign', $query->quoteIdentifier('metadata.uid'))
            )
            ->join(
                'relation',
                'sys_category',
                'category',
                $query->expr()->eq('category.uid', $query->quoteIdentifier('relation.uid_local'))
            )
            ->where(
                $query->expr()->in('metadata.file', $query->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)),
                $query->expr()->eq('relation.tablenames', $query->createNamedParameter('sys_file_metadata')),
                $query->expr()->eq('relation.fieldname', $query->createNamedParameter('categories')),
                $query->expr()->eq('metadata.sys_language_uid', $query->createNamedParameter(0, Connection::PARAM_INT)),
            )->orderBy('category.title')->addOrderBy('category.uid')
            ->executeQuery()->fetchAllAssociative();
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int)$row['file']][(int)$row['uid']] = CategoryStyle::item($row);
        }
        return array_map('array_values', $groups);
    }

    /** Only receives database form IDs already authorized by Core. */
    public function forDatabaseForms(array $uids): array
    {
        return array_map(CategoryStyle::title(...), $this->itemsForDatabaseForms($uids));
    }
    public function itemsForDatabaseForms(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $keyMap = [];
        foreach ($uids as $uid) {
            $keyMap[WorkspaceData::key((string)$uid)] = $uid;
        }
        $query = $this->connectionPool->getQueryBuilderForTable('tx_formmanagerplus_profile');
        $profiles = $query->select('form_key', 'categories_json')->from('tx_formmanagerplus_profile')
            ->where($query->expr()->in('form_key', $query->createNamedParameter(array_keys($keyMap), Connection::PARAM_STR_ARRAY)))->executeQuery()->fetchAllAssociative();
        $assignments = $ids = [];
        foreach ($profiles as $profile) {
            $assigned = array_map('intval', json_decode($profile['categories_json'] ?: '[]', true) ?: []);
            $assignments[$keyMap[$profile['form_key']]] = $assigned;
            $ids = array_merge($ids, $assigned);
        }
        if (!$ids) {
            return [];
        }
        $query = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $titles = [];
        $user = $GLOBALS['BE_USER'] ?? null;
        foreach ($query->select('uid', 'pid', 'title', 'tx_fmp_icon', 'tx_fmp_color')->from('sys_category')->where($query->expr()->in('uid', $query->createNamedParameter(array_values(array_unique($ids)), Connection::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
            if ($user && !$user->isAdmin() && (!$user->check('tables_select', 'sys_category') || !\TYPO3\CMS\Backend\Utility\BackendUtility::readPageAccess((int)$row['pid'], $user->getPagePermsClause(1)))) {
                continue;
            }
            $titles[(int)$row['uid']] = CategoryStyle::item($row);
        }
        $groups = [];
        foreach ($assignments as $uid => $assigned) {
            $labels = [];
            foreach ($assigned as $category) {
                if (isset($titles[$category])) {
                    $labels[] = $titles[$category];
                }
            }
            usort($labels, static fn($a, $b) => strnatcasecmp($a['title'], $b['title']) ?: ($a['uid'] <=> $b['uid']));
            $groups[$uid] = $labels;
        }
        return $groups;
    }
}
