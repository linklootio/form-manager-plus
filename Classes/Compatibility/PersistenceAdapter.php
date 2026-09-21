<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

interface PersistenceAdapter
{
    public function allowedIdentifier(string $identifier, array $settings): bool;
    public function load(string $identifier, array $settings): array;
    public function targets(array $settings): array;
    public function create(array $definition, array $target, array $settings): string;
}
