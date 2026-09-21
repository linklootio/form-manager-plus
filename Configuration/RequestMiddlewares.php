<?php

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 13) {
    return [];
}
return ['backend' => [
    'form-manager-plus/recent-edits' => [
        'target' => \SchefferWebdesign\FormManagerPlus\Middleware\RecentEdits::class,
        'after' => ['typo3/cms-backend/authentication'],
    ],
    'form-manager-plus/catalog-invalidation' => [
        'target' => \SchefferWebdesign\FormManagerPlus\Middleware\InvalidateCatalog::class,
        'after' => ['typo3/cms-backend/authentication'],
    ]]];
