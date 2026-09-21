<?php

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 13) {
    return [];
}
return ['form_manager_plus_tools' => [
    'path' => '/form-manager-plus/workbench',
    'target' => \SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class . '::page',
]];
