<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

/** Shared allowlist for native FormEngine choices and safe list rendering. */
final class CategoryStyle
{
    public const ICONS = ['folder', 'star', 'calendar', 'envelope', 'user', 'users', 'tag', 'heart', 'briefcase', 'bell', 'check'];

    public static function item(array $row): array
    {
        $icon = in_array($row['tx_fmp_icon'] ?? '', self::ICONS, true) ? $row['tx_fmp_icon'] : 'folder';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/D', (string)($row['tx_fmp_color'] ?? '')) ? strtolower($row['tx_fmp_color']) : '';
        $ink = '';
        if ($color !== '') {
            $channels = array_map(static function ($channel): float {
                $s = hexdec($channel) / 255;
                return $s <= .04045 ? $s / 12.92 : (($s + .055) / 1.055) ** 2.4;
            }, str_split(substr($color, 1), 2));
            $ink = .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2] > .179 ? '#000000' : '#ffffff';
        }
        return ['uid' => (int)$row['uid'], 'title' => (string)$row['title'], 'icon' => $icon, 'color' => $color, 'ink' => $ink];
    }
    public static function title(array $items): string
    {
        return implode(' / ', array_column($items, 'title'));
    }
}
