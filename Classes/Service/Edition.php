<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class Edition
{
    public static function pro(): bool
    {
        return ExtensionManagementUtility::isLoaded('form_manager_plus_pro');
    }
    public static function requirePro(): void
    {
        if (!self::pro()) {
            throw new \DomainException('pro_required');
        }
    }
    public static function proOperation(string $operation): bool
    {
        return in_array($operation, ['bulk_options', 'bulk', 'import_match', 'history', 'category_appearance', 'views', 'view_save', 'view_delete', 'export', 'targets', 'preview', 'import'], true);
    }
}
