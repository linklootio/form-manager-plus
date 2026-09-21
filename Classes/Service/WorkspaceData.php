<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Private tables: never exposed through generic DataHandler or record lists. */
final class WorkspaceData
{
    public function __construct(private ConnectionPool $pool) {}
    public static function key(string $identifier): string
    {
        return hash('sha256', $identifier);
    }
    private function owner(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user || empty($user->user['uid'])) {
            throw new \DomainException('access');
        }
        return ['be_user' => (int)$user->user['uid'], 'workspace' => (int)$user->workspace];
    }
    private function upsert(string $table, array $key, array $values): void
    {
        $db = $this->pool->getConnectionForTable($table);
        if ($db->count('*', $table, $key)) {
            $db->update($table, $values, $key);
            return;
        }
        try {
            $db->insert($table, $key + $values);
        } catch (UniqueConstraintViolationException) {
            $db->update($table, $values, $key);
        }
    }
    public function profile(string $identifier): array
    {
        $row = $this->pool->getConnectionForTable('tx_formmanagerplus_profile')->select(['*'], 'tx_formmanagerplus_profile', ['form_key' => self::key($identifier)])->fetchAssociative();
        if ($row) {
            // Newly created profiles leave the nullable TEXT column unset.
            // The profile API and Lite's preservation path require a string.
            $row['notes'] ??= '';
        }
        return $row ?: ['purpose' => '', 'responsible' => '', 'responsible_user' => 0, 'notes' => '', 'revision' => 0];
    }
    public function assignCreator(string $identifier): void
    {
        $owner = $this->owner();
        if ($owner['workspace'] !== 0) {
            return;
        }
        if ($owner['be_user'] < 1 || $identifier === '') {
            return;
        }
        $before = $this->profile($identifier);
        if ((int)($before['responsible_user'] ?? 0) > 0) {
            return;
        }
        $db = $this->pool->getConnectionForTable('tx_formmanagerplus_profile');
        $values = ['responsible_user' => $owner['be_user'], 'revision' => (int)$before['revision'] + 1,
            'updated_at' => time(), 'updated_by' => $owner['be_user'], 'form_identifier' => $identifier];
        if (isset($before['uid'])) {
            if ($db->update('tx_formmanagerplus_profile', $values, ['uid' => $before['uid'], 'revision' => (int)$before['revision'], 'responsible_user' => 0]) !== 1) {
                return;
            }
        } else {
            try {
                $db->insert('tx_formmanagerplus_profile', ['form_key' => self::key($identifier)] + $values);
            } catch (UniqueConstraintViolationException) {
                return;
            }
        }
        $this->recordHistory($identifier, ['responsible_user' => 0], ['responsible_user' => $owner['be_user']]);
        $this->edited($identifier);
    }
    /** Only active users visible through native table permissions, or oneself. */
    public function responsibleUsers(): array
    {
        $owner = $this->owner();
        $user = $GLOBALS['BE_USER'];
        $q = $this->pool->getQueryBuilderForTable('be_users');
        $q->select('uid', 'username', 'realName')->from('be_users')
            ->where(
                $q->expr()->eq('disable', 0),
                $q->expr()->eq('deleted', 0),
                $q->expr()->lte('starttime', $q->createNamedParameter(time(), Connection::PARAM_INT)),
                $q->expr()->or($q->expr()->eq('endtime', 0), $q->expr()->gt('endtime', $q->createNamedParameter(time(), Connection::PARAM_INT)))
            )
            ->orderBy('realName')->addOrderBy('username');
        if (!$user->isAdmin() && !$user->check('tables_select', 'be_users')) {
            $q->andWhere($q->expr()->eq('uid', $q->createNamedParameter($owner['be_user'], Connection::PARAM_INT)));
        }
        return array_map(static fn(array $row): array => ['uid' => (int)$row['uid'],
            'label' => trim((string)$row['realName']) ?: (string)$row['username']], $q->executeQuery()->fetchAllAssociative());
    }
    public function saveProfile(string $identifier, array $input): array
    {
        if ($this->owner()['workspace'] !== 0) {
            throw new \DomainException('workspace_readonly');
        }
        $before = $this->profile($identifier);
        $values = [];
        foreach (['purpose' => 255, 'responsible' => 255, 'notes' => 10000] as $field => $limit) {
            if (!is_string($input[$field] ?? null) || mb_strlen($input[$field]) > $limit) {
                throw new \InvalidArgumentException('input');
            }
            $values[$field] = trim($input[$field]);
        }
        if (array_key_exists('responsible_user', $input)) {
            $selected = $input['responsible_user'];
            if (!is_int($selected) || $selected < 0) {
                throw new \InvalidArgumentException('input');
            }
            $current = (int)($this->profile($identifier)['responsible_user'] ?? 0);
            if ($selected !== 0 && $selected !== $current && !in_array($selected, array_column($this->responsibleUsers(), 'uid'), true)) {
                throw new \DomainException('access');
            }
            $values['responsible_user'] = $selected;
        }
        $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 0) {
            throw new \InvalidArgumentException('input');
        }
        $key = self::key($identifier);
        $values += ['form_identifier' => $identifier, 'revision' => $revision + 1, 'updated_at' => time(), 'updated_by' => $this->owner()['be_user']];
        $db = $this->pool->getConnectionForTable('tx_formmanagerplus_profile');
        if ($db->count('*', 'tx_formmanagerplus_profile', ['form_key' => $key])) {
            if ($db->update('tx_formmanagerplus_profile', $values, ['form_key' => $key, 'revision' => $revision]) !== 1) {
                throw new \DomainException('conflict');
            }
        } else {
            if ($revision !== 0) {
                throw new \DomainException('conflict');
            }
            try {
                $db->insert('tx_formmanagerplus_profile', ['form_key' => $key] + $values);
            } catch (UniqueConstraintViolationException) {
                throw new \DomainException('conflict');
            }
        }
        $after = $this->profile($identifier);
        $this->recordHistory($identifier, array_intersect_key($before, array_flip(['purpose', 'responsible', 'responsible_user', 'notes'])), array_intersect_key($after, array_flip(['purpose', 'responsible', 'responsible_user', 'notes'])));
        return $after;
    }
    public function favorite(string $identifier, bool $favorite): void
    {
        $this->upsert('tx_formmanagerplus_personal', $this->owner() + ['form_key' => self::key($identifier)], ['favorite' => (int)$favorite]);
    }
    public function categories(): array
    {
        $user = $GLOBALS['BE_USER'];
        if (!$user->check('tables_select', 'sys_category')) {
            return [];
        }
        $q = $this->pool->getQueryBuilderForTable('sys_category');
        $rows = $q->select('uid', 'pid', 'parent', 'title')->from('sys_category')->orderBy('title')->executeQuery()->fetchAllAssociative();
        return array_values(array_filter($rows, static function ($row) use ($user): bool {
            if ($user->isAdmin()) {
                return true;
            }
            return (bool)\TYPO3\CMS\Backend\Utility\BackendUtility::readPageAccess((int)$row['pid'], $user->getPagePermsClause(1));
        }));
    }
    public function saveCategories(string $identifier, array $ids, int $revision): array
    {
        if ($this->owner()['workspace'] !== 0) {
            throw new \DomainException('workspace_readonly');
        }
        if (count($ids) > 50 || $revision < 0) {
            throw new \InvalidArgumentException('input');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('input');
            }
        }
        $allowed = array_map('intval', array_column($this->categories(), 'uid'));
        if (array_diff($ids, $allowed)) {
            throw new \DomainException('access');
        }
        $profile = $this->profile($identifier);
        $existing = array_map('intval', json_decode($profile['categories_json'] ?? '[]', true) ?: []);
        // Preserve existing assignments the current user cannot inspect.
        $ids = array_values(array_unique(array_merge($ids, array_diff($existing, $allowed))));
        sort($ids);
        $db = $this->pool->getConnectionForTable('tx_formmanagerplus_profile');
        $key = self::key($identifier);
        $values = ['categories_json' => json_encode($ids, JSON_THROW_ON_ERROR), 'revision' => $revision + 1,
            'updated_at' => time(), 'updated_by' => $this->owner()['be_user'], 'form_identifier' => $identifier];
        if (isset($profile['uid'])) {
            if ($db->update('tx_formmanagerplus_profile', $values, ['form_key' => $key, 'revision' => $revision]) !== 1) {
                throw new \DomainException('conflict');
            }
        } else {
            if ($revision !== 0) {
                throw new \DomainException('conflict');
            }
            try {
                $db->insert('tx_formmanagerplus_profile', ['form_key' => $key] + $values);
            } catch (UniqueConstraintViolationException) {
                throw new \DomainException('conflict');
            }
        }
        $this->edited($identifier);
        $this->recordHistory($identifier, ['categories' => $existing], ['categories' => $ids]);
        return $this->profile($identifier);
    }
    public function edited(string $identifier): void
    {
        $this->upsert('tx_formmanagerplus_personal', $this->owner() + ['form_key' => self::key($identifier)], ['last_edited' => time()]);
    }
    /** Personal rows are scoped server-side, never by client-supplied user IDs. */
    public function enrich(array $forms): array
    {
        $personal = [];
        foreach ($this->pool->getConnectionForTable('tx_formmanagerplus_personal')->select(['*'], 'tx_formmanagerplus_personal', $this->owner())->fetchAllAssociative() as $row) {
            $personal[$row['form_key']] = $row;
        }
        $profiles = [];
        foreach (array_chunk(array_map(static fn($f) => self::key($f['persistenceIdentifier']), $forms), 400) as $keys) {
            $q = $this->pool->getQueryBuilderForTable('tx_formmanagerplus_profile');
            foreach ($q->select('form_key', 'purpose', 'responsible', 'responsible_user')->from('tx_formmanagerplus_profile')
                ->where($q->expr()->in('form_key', $q->createNamedParameter($keys, Connection::PARAM_STR_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
                $profiles[$row['form_key']] = $row;
            }
        }
        foreach ($forms as &$form) {
            $key = self::key($form['persistenceIdentifier']);
            $form['favorite'] = !empty($personal[$key]['favorite']);
            $form['lastEdited'] = (int)($personal[$key]['last_edited'] ?? 0);
            $form['purpose'] = $profiles[$key]['purpose'] ?? '';
            $form['responsible'] = $profiles[$key]['responsible'] ?? '';
            $form['responsibleUser'] = (int)($profiles[$key]['responsible_user'] ?? 0);
            $form['mine'] = $form['responsibleUser'] === $this->owner()['be_user'];
        }
        unset($form);
        return $forms;
    }
    public function teamGroups(): array
    {
        $user = $GLOBALS['BE_USER'];
        $ids = array_map('intval', $user->userGroupsUID ?? []);
        $q = $this->pool->getQueryBuilderForTable('be_groups');
        $q->select('uid', 'title')->from('be_groups')->where($q->expr()->eq('hidden', 0), $q->expr()->eq('deleted', 0))->orderBy('title');
        if (!$user->isAdmin()) {
            if (!$ids) {
                return [];
            }
            $q->andWhere($q->expr()->in('uid', $q->createNamedParameter($ids, Connection::PARAM_INT_ARRAY)));
        }
        return array_map(static fn($row) => ['uid' => (int)$row['uid'], 'title' => $row['title']], $q->executeQuery()->fetchAllAssociative());
    }
    public function views(): array
    {
        $owner = $this->owner();
        $groups = array_column($this->teamGroups(), 'title', 'uid');
        $rows = $this->pool->getConnectionForTable('tx_formmanagerplus_view')->select(['*'], 'tx_formmanagerplus_view', ['workspace' => $owner['workspace']], [], ['name' => 'ASC'])->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $group = (int)$row['be_group'];
            $own = (int)$row['be_user'] === $owner['be_user'];
            if ($group ? !isset($groups[$group]) : !$own) {
                continue;
            }
            $result[] = ['id' => (int)$row['uid'], 'name' => $row['name'], 'state' => json_decode($row['state_json'], true),
                'group' => $group, 'groupLabel' => $groups[$group] ?? '', 'editable' => $own || ($group && $GLOBALS['BE_USER']->isAdmin()), 'updatedAt' => (int)$row['updated_at']];
        }
        return $result;
    }
    public function saveView(array $input): array
    {
        if (!is_string($input['name'] ?? null) || (isset($input['id']) && !is_int($input['id'])) || (isset($input['group']) && !is_int($input['group']))) {
            throw new \InvalidArgumentException('input');
        }
        $name = trim($input['name']);
        $state = $input['state'] ?? null;
        $group = $input['group'] ?? 0;
        if (!$name || mb_strlen($name) > 80 || !is_array($state) || $group < 0) {
            throw new \InvalidArgumentException('input');
        }
        if ($group && !in_array($group, array_column($this->teamGroups(), 'uid'), true)) {
            throw new \DomainException('access');
        }
        $parsed = PageQuery::parse($state);
        $safe = array_intersect_key($parsed, array_flip(['start', 'length', 'search', 'category', 'sort', 'mode', 'grouped', 'responsible', 'team', 'storage']));
        $safe['direction'] = $parsed['direction'] === -1 ? 'desc' : 'asc';
        $db = $this->pool->getConnectionForTable('tx_formmanagerplus_view');
        $owner = $this->owner();
        $id = max(0, (int)($input['id'] ?? 0));
        if (!$id && $db->count('*', 'tx_formmanagerplus_view', $owner) >= 20) {
            throw new \DomainException('view_limit');
        }
        $values = ['name' => $name, 'be_group' => $group, 'name_key' => hash('sha256', mb_strtolower($name)), 'state_json' => json_encode($safe, JSON_THROW_ON_ERROR), 'updated_at' => time()];
        try {
            if ($id) {
                $view = array_values(array_filter($this->views(), static fn($view) => $view['id'] === $id))[0] ?? null;
                if (!$view || !$view['editable']) {
                    throw new \DomainException('access');
                }
                $db->update('tx_formmanagerplus_view', $values, ['uid' => $id, 'workspace' => $owner['workspace']]);
            } else {
                $db->insert('tx_formmanagerplus_view', $owner + $values);
            }
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('view_exists');
        }
        return $this->views();
    }
    public function deleteView(int $id): array
    {
        $view = array_values(array_filter($this->views(), static fn($view) => $view['id'] === $id))[0] ?? null;
        if ($view && $view['editable']) {
            $this->pool->getConnectionForTable('tx_formmanagerplus_view')->delete('tx_formmanagerplus_view', ['uid' => $id, 'workspace' => $this->owner()['workspace']]);
        }
        return $this->views();
    }
    public function recordHistory(string $identifier, array $before, array $after): void
    {
        if (!Edition::pro()) {
            return;
        }
        $changes = [];
        foreach (['purpose', 'responsible', 'responsible_user', 'notes', 'categories'] as $field) {
            if (!array_key_exists($field, $after)) {
                continue;
            }
            $old = $before[$field] ?? ($field === 'categories' ? [] : ($field === 'responsible_user' ? 0 : ''));
            $new = $after[$field];
            if ($field === 'categories') {
                $old = array_map('intval', $old);
                $new = array_map('intval', $new);
                sort($old);
                sort($new);
            }
            if ($field === 'responsible_user') {
                $old = (int)$old;
                $new = (int)$new;
            }
            if (!in_array($field, ['categories', 'responsible_user'], true)) {
                $old = (string)$old;
                $new = (string)$new;
            }
            if ($old !== $new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }
        if (!$changes) {
            return;
        }
        $owner = $this->owner();
        $user = $GLOBALS['BE_USER']->user;
        $this->pool->getConnectionForTable('tx_formmanagerplus_history')->insert('tx_formmanagerplus_history', [
            'form_key' => self::key($identifier), 'workspace' => $owner['workspace'], 'actor' => $owner['be_user'],
            'actor_label' => mb_substr(trim((string)($user['realName'] ?? '')) ?: (string)($user['username'] ?? $owner['be_user']), 0, 255),
            'created_at' => time(), 'changes_json' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
    public function history(string $identifier, int $beforeId = 0): array
    {
        $owner = $this->owner();
        $q = $this->pool->getQueryBuilderForTable('tx_formmanagerplus_history');
        $q->select('*')->from('tx_formmanagerplus_history')->where($q->expr()->eq('form_key', $q->createNamedParameter(self::key($identifier))), $q->expr()->eq('workspace', $q->createNamedParameter($owner['workspace'], Connection::PARAM_INT)));
        if ($beforeId > 0) {
            $q->andWhere($q->expr()->lt('uid', $q->createNamedParameter($beforeId, Connection::PARAM_INT)));
        }
        $rows = $q->orderBy('uid', 'DESC')->setMaxResults(51)->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $categories = array_column($this->categories(), 'title', 'uid');
        $users = array_column($this->responsibleUsers(), 'label', 'uid');
        $items = [];
        foreach ($rows as $row) {
            $changes = json_decode($row['changes_json'], true) ?: [];
            foreach ($changes as $field => &$change) {
                foreach (['before', 'after'] as $side) {
                    if ($field === 'categories') {
                        $change[$side] = array_values(array_map(static fn($id) => $categories[$id] ?? '[restricted]', $change[$side]));
                    }
                    if ($field === 'responsible_user') {
                        $change[$side] = $change[$side] ? ($users[$change[$side]] ?? '[restricted]') : '';
                    }
                }
            }
            unset($change);
            $items[] = ['id' => (int)$row['uid'], 'at' => (int)$row['created_at'], 'actor' => $row['actor_label'], 'changes' => $changes];
        }
        return ['items' => $items, 'next' => $more ? (int)end($rows)['uid'] : null];
    }
}
