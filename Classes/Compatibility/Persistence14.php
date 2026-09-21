<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

final class Persistence14 implements PersistenceAdapter
{
    public function __construct(private FormPersistenceManagerInterface $persistence) {}

    public function allowedIdentifier(string $identifier, array $settings): bool
    {
        return $this->persistence->isAllowedPersistenceIdentifier($identifier);
    }
    public function load(string $identifier, array $settings): array
    {
        return $this->persistence->load($identifier);
    }
    public function targets(array $settings): array
    {
        $targets = [];
        foreach ($this->persistence->getAccessibleStorageAdapters() as $adapter) {
            if (!in_array($adapter['typeIdentifier'], ['database', 'filemount'], true)) {
                continue;
            }
            foreach ($adapter['options']['allowedStorageLocations'] ?? [] as $location) {
                $targets[] = ['key' => $adapter['typeIdentifier'] . ':' . $location['value'], 'type' => $adapter['typeIdentifier'], 'value' => (string)$location['value'], 'label' => $location['label']];
            }
        }
        return $targets;
    }
    public function create(array $definition, array $target, array $settings): string
    {
        if (!$this->persistence->isAllowedStorageLocation($target['value'])) {
            throw new \DomainException('target');
        }
        $definition['identifier'] = $this->persistence->getUniqueIdentifier($definition['identifier']);
        $identifier = $this->persistence->getUniquePersistenceIdentifier($target['type'], $definition['identifier'], $target['value']);
        return $this->persistence->save($identifier, $definition, [], $target['value'])->identifier;
    }
}
