<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\ViewHelpers;

use SchefferWebdesign\FormManagerPlus\Service\FormList;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/** Exposes the complete authorized list, independently of Core pagination. */
final class FormsViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private FormList $formList;

    public function injectFormList(FormList $formList): void
    {
        $this->formList = $formList;
    }

    /** @return list<array<string, mixed>> */
    public function render(): array
    {
        return $this->formList->getForms();
    }
}
