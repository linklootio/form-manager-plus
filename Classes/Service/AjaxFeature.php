<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;

final class AjaxFeature
{
    public static function enabled(): bool
    {
        return (new Typo3Version())->getMajorVersion() >= 13
            && (BackendUtility::getPagesTSconfig(0)['templates.']['typo3/cms-form.']['1700'] ?? '') === 'scheffer-webdesign/form-manager-plus:Resources/Private';
    }
    public static function allowed(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user && !empty($user->user['uid'])
            && $user->check('modules', (new Typo3Version())->getMajorVersion() >= 14 ? 'form_manager' : 'web_FormFormbuilder');
    }
}
