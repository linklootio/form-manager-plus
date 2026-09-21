<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use Psr\Http\Message\ResponseInterface;

final class UnavailableProOperations implements ProOperationsInterface
{
    public function handle(string $operation, array $input, array $headers): ResponseInterface
    {
        throw new \DomainException('pro_required');
    }
}
