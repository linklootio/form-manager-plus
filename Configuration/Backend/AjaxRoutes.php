<?php

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 13) {
    return [];
}
return [
    'form_manager_plus_tools' => [
        'path' => '/form-manager-plus/tools',
        'target' => \SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class . '::api',
    ],
    'form_manager_plus_list' => [
        'path' => '/form-manager-plus/list',
        'target' => \SchefferWebdesign\FormManagerPlus\Controller\ListController::class . '::listAction',
    ],
];
