<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

final class PageQuery
{
    public static function storageKey(string $identifier): string
    {
        if (ctype_digit($identifier)) {
            return 'database';
        }
        $slash = strrpos($identifier, '/');
        return $slash === false ? $identifier : substr($identifier, 0, $slash + 1);
    }
    public static function storageFacets(array $forms): array
    {
        $facets = [];
        foreach ($forms as $form) {
            $key = self::storageKey((string)$form['persistenceIdentifier']);
            $facets[$key] ??= ['key' => $key, 'count' => 0];
            $facets[$key]['count']++;
        }
        ksort($facets, SORT_NATURAL);
        return array_values($facets);
    }
    public static function categoryFacets(array $forms): array
    {
        $facets = [];
        $uncategorised = 0;
        foreach ($forms as $form) {
            $items = $form['categoryItems'] ?? [];
            if (!$items) {
                $uncategorised++;
                continue;
            }
            $seen = [];
            foreach ($items as $item) {
                $uid = (int)$item['uid'];
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                $facets[$uid] ??= $item + ['key' => 'id:' . $uid, 'count' => 0];
                $facets[$uid]['count']++;
            }
        }
        usort($facets, static fn($a, $b) => strnatcasecmp($a['title'], $b['title']) ?: ($a['uid'] <=> $b['uid']));
        if ($uncategorised) {
            array_unshift($facets, ['key' => 'group:', 'uid' => 0, 'title' => '', 'icon' => 'folder', 'color' => '', 'ink' => '', 'count' => $uncategorised]);
        }
        return $facets;
    }
    public static function matchesMode(array $form, string $mode): bool
    {
        return match ($mode) {
            'mine' => !empty($form['mine']),
            'favorites' => !empty($form['favorite']),
            'recent' => !empty($form['lastEdited']),
            'unreferenced' => (int)($form['referenceCount'] ?? 0) === 0,
            'invalid' => !empty($form['invalid']),
            'stale' => !empty($form['modifiedAt']) && $form['modifiedAt'] < time() - 180 * 86400,
            default => true,
        };
    }
    public static function parse(array $input): array
    {
        foreach (['draw', 'start', 'length', 'search', 'sort', 'direction', 'category', 'refresh', 'mode', 'grouped', 'responsible', 'team', 'storage'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new \InvalidArgumentException('Invalid query');
            }
        }
        $length = filter_var($input['length'] ?? 25, FILTER_VALIDATE_INT);
        $start = filter_var($input['start'] ?? 0, FILTER_VALIDATE_INT);
        if ($length === false || $length < 1 || $length > 250 || $start === false || $start < 0 || $start > 10000000) {
            throw new \InvalidArgumentException('Invalid page');
        }
        return [
            'draw' => max(0, min(2147483647, (int)($input['draw'] ?? 0))),
            'start' => $start, 'length' => $length,
            'search' => mb_substr(trim((string)($input['search'] ?? '')), 0, 200),
            'category' => mb_substr((string)($input['category'] ?? ''), 0, 500),
            'mode' => in_array($input['mode'] ?? '', ['mine', 'favorites', 'recent', 'unreferenced', 'stale', 'invalid'], true) ? $input['mode'] : '',
            'responsible' => preg_match('/^(?:[0-9]{1,10}|unassigned)$/D', (string)($input['responsible'] ?? '')) ? (string)$input['responsible'] : '',
            'storage' => mb_substr((string)($input['storage'] ?? ''), 0, 1024),
            'team' => mb_substr(trim((string)($input['team'] ?? '')), 0, 255),
            'sort' => in_array($input['sort'] ?? '', ['fileUid', 'name', 'purpose', 'group', 'persistenceIdentifier', 'referenceCount', 'modifiedAt', 'lastEdited'], true) ? $input['sort'] : 'name',
            'grouped' => !in_array($input['grouped'] ?? '1', ['0', 0, false], true),
            'direction' => ($input['direction'] ?? '') === 'desc' ? -1 : 1,
            'refresh' => ($input['refresh'] ?? '') === '1',
        ];
    }
    public static function select(array $forms, array $query): array
    {
        $categories = array_values(array_unique(array_column($forms, 'group')));
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        $filtered = array_values(array_filter($forms, static function (array $form) use ($query): bool {
            $mode = $query['mode'] ?? '';
            if (($query['storage'] ?? '') !== '' && self::storageKey((string)$form['persistenceIdentifier']) !== $query['storage']) {
                return false;
            }
            if (!self::matchesMode($form, $mode)) {
                return false;
            }
            if (($query['responsible'] ?? '') !== '') {
                $assigned = $query['responsible'] === 'unassigned' ? 0 : (int)$query['responsible'];
                if ((int)($form['responsibleUser'] ?? 0) !== $assigned) {
                    return false;
                }
            }
            if (($query['team'] ?? '') !== '' && mb_stripos((string)($form['responsible'] ?? ''), $query['team']) === false) {
                return false;
            }
            $category = $query['category'];
            if (str_starts_with($category, 'id:')) {
                if (!preg_match('/^id:([1-9][0-9]*)$/D', $category, $match)
                    || !in_array((int)$match[1], array_map('intval', array_column($form['categoryItems'] ?? [], 'uid')), true)) {
                    return false;
                }
            } elseif ($category !== '' && 'group:' . ($form['group'] ?? '') !== $category) {
                // Keep old saved title filters usable; new filters use stable UIDs.
                if (!str_starts_with($category, 'group:') || !in_array(substr($category, 6), array_column($form['categoryItems'] ?? [], 'title'), true)) {
                    return false;
                }
            }
            if ($query['search'] === '') {
                return true;
            }
            foreach (['fileUid', 'name', 'persistenceIdentifier', 'group', 'purpose', 'responsible'] as $key) {
                if (mb_stripos((string)($form[$key] ?? ''), $query['search']) !== false) {
                    return true;
                }
            }
            return false;
        }));
        usort($filtered, static function (array $a, array $b) use ($query): int {
            if ($query['sort'] === 'lastEdited') {
                return $query['direction'] * ((int)($a['lastEdited'] ?? 0) <=> (int)($b['lastEdited'] ?? 0)) ?: strcmp($a['persistenceIdentifier'], $b['persistenceIdentifier']);
            }
            $group = strnatcasecmp($a['group'] ?? '', $b['group'] ?? '');
            if (($query['grouped'] ?? true) && $group) {
                return $group;
            }
            $field = $query['sort'];
            $value = in_array($field, ['fileUid', 'referenceCount', 'modifiedAt', 'lastEdited'], true)
                ? ((int)($a[$field] ?? 0) <=> (int)($b[$field] ?? 0))
                : strnatcasecmp((string)($a[$field] ?? ''), (string)($b[$field] ?? ''));
            return $query['direction'] * $value ?: strcmp($a['persistenceIdentifier'], $b['persistenceIdentifier']);
        });
        $start = count($filtered) && $query['start'] >= count($filtered)
            ? (int)(floor((count($filtered) - 1) / $query['length']) * $query['length']) : $query['start'];
        return ['draw' => $query['draw'], 'recordsTotal' => count($forms), 'recordsFiltered' => count($filtered),
            'start' => $start, 'categories' => $categories, 'forms' => array_slice($filtered, $start, $query['length'])];
    }
}
