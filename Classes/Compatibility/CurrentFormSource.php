<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as FormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/** TYPO3 13 passes effective backend YAML settings explicitly. */
final class CurrentFormSource implements FormSourceInterface
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
        return array_values($this->persistenceManager->listForms($formSettings));
    }
}
