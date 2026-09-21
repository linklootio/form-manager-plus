<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

final class UiText
{
    public static function get(string $key): string
    {
        return (string)$GLOBALS['LANG']->sL('LLL:EXT:form_manager_plus/Resources/Private/Language/locallang.xlf:fmp.' . $key);
    }
}
