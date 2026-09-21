<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

final class Persistence13 implements PersistenceAdapter
{
    public function __construct(private FormPersistenceManagerInterface $persistence) {}

    public function allowedIdentifier(string $identifier, array $settings): bool
    {
        return $this->persistence->isAllowedPersistencePath($identifier, $settings);
    }
    public function load(string $identifier, array $settings): array
    {
        return $this->persistence->load($identifier, $settings, []);
    }
    public function targets(array $settings): array
    {
        $targets = [];
        foreach ($this->persistence->getAccessibleFormStorageFolders($settings) as $folder) {
            if (!$folder->checkActionPermission('write')) {
                continue;
            }
            $value = $folder->getCombinedIdentifier();
            $targets[] = ['key' => 'file:' . $value, 'type' => 'file', 'value' => $value, 'label' => $folder->getStorage()->getName() . ' · ' . $folder->getIdentifier()];
        }
        return $targets;
    }
    public function create(array $definition, array $target, array $settings): string
    {
        if (!$this->persistence->isAllowedPersistencePath($target['value'], $settings)) {
            throw new \DomainException('target');
        }
        $definition['identifier'] = $this->persistence->getUniqueIdentifier($settings, $definition['identifier']);
        $identifier = $this->persistence->getUniquePersistenceIdentifier($definition['identifier'], $target['value'], $settings);
        $this->persistence->save($identifier, $definition, $settings);
        return $identifier;
    }
}
