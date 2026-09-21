<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

/** Normalizes only the read side of the supported Form Framework versions. */
interface FormSourceInterface
{
    /** @return list<array<string, mixed>> */
    public function listForms(): array;
}
