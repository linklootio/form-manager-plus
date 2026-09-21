<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\DTO\FormMetadata;
use TYPO3\CMS\Form\Domain\DTO\SearchCriteria;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as FormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/** TYPO3 14 returns metadata DTOs for file and database forms. */
final class ModernFormSource implements FormSourceInterface
{
    public function __construct(
        private FormPersistenceManagerInterface $persistenceManager,
        private ConfigurationManagerInterface $configurationManager,
        private FormConfigurationManagerInterface $formConfigurationManager,
    ) {}

    public function listForms(): array
    {
        $settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'form',
        );
        $formSettings = $this->formConfigurationManager->getYamlConfiguration($settings, false);
        return array_map(
            static fn(FormMetadata $metadata): array => $metadata->toArray(),
            array_values($this->persistenceManager->listForms($formSettings, new SearchCriteria())),
        );
    }
}
