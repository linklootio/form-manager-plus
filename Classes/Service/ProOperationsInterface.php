<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use Psr\Http\Message\ResponseInterface;

interface ProOperationsInterface
{
    public function handle(string $operation, array $input, array $headers): ResponseInterface;
}
