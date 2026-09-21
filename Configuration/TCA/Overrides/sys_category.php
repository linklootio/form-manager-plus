<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

use SchefferWebdesign\FormManagerPlus\Service\CategoryStyle;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

if (!\SchefferWebdesign\FormManagerPlus\Service\Edition::pro()) {
    return;
}
$ll = 'LLL:EXT:form_manager_plus/Resources/Private/Language/locallang.xlf:';
$items = [];
foreach (CategoryStyle::ICONS as $icon) {
    $items[] = ['label' => $ll . 'categoryIcon.' . $icon, 'value' => $icon, 'icon' => 'actions-' . $icon];
}
ExtensionManagementUtility::addTCAcolumns('sys_category', [
    'tx_fmp_icon' => ['exclude' => true, 'label' => $ll . 'categoryIcon',
        'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'default' => 'folder', 'items' => $items,
            'fieldWizard' => ['selectIcons' => ['disabled' => false]]]],
    'tx_fmp_color' => ['exclude' => true, 'label' => $ll . 'categoryColor',
        'description' => $ll . 'categoryColorHelp',
        'config' => ['type' => 'color', 'default' => '', 'valuePicker' => ['items' => [
            [$ll . 'categoryColor.orange', '#ff8700'], [$ll . 'categoryColor.blue', '#2563eb'],
            [$ll . 'categoryColor.green', '#15803d'], [$ll . 'categoryColor.purple', '#7c3aed'],
            [$ll . 'categoryColor.red', '#dc2626'], [$ll . 'categoryColor.teal', '#0f766e'],
            [$ll . 'categoryColor.gray', '#64748b'],
        ]]]],
]);
ExtensionManagementUtility::addToAllTCAtypes('sys_category', '--div--;' . $ll . 'categoryAppearance,tx_fmp_icon,tx_fmp_color');

if ((new Typo3Version())->getMajorVersion() >= 14) {
    $GLOBALS['TCA']['sys_category']['columns']['tx_fmp_color']['config']['valuePicker']['items'] = array_map(static fn(array $item): array => ['label' => $item[0], 'value' => $item[1]], $GLOBALS['TCA']['sys_category']['columns']['tx_fmp_color']['config']['valuePicker']['items']);
}
