<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use Psr\Http\Message\ServerRequestInterface;

final class ButtonRequest
{
    public static function from(object $event): ?ServerRequestInterface
    {
        return method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
    }
}
