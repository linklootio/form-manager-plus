<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as FormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

final class FormAccess
{
    public function __construct(
        private FormCatalog $catalog,
        private ResourceFactory $resources,
        private FormPersistenceManagerInterface $persistence,
        private ConfigurationManagerInterface $configuration,
        private FormConfigurationManagerInterface $formConfiguration,
        private \SchefferWebdesign\FormManagerPlus\Compatibility\PersistenceAdapter $adapter,
        private FormLifecycle $lifecycle
    ) {}
    public function settings(): array
    {
        return $this->formConfiguration->getYamlConfiguration($this->configuration->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, 'form'), false);
    }
    public function clearCatalog(): void
    {
        $this->catalog->clear();
    }
    public function find(string $identifier, bool $write = false): array
    {
        if ($write && (int)($GLOBALS['BE_USER']->workspace ?? 0) !== 0) {
            throw new \DomainException('workspace_readonly');
        }
        if (!AjaxFeature::allowed() || !AjaxFeature::enabled() || strlen($identifier) > 1024) {
            throw new \DomainException('access');
        }
        foreach ($this->catalog->forms() as $form) {
            if ($form['persistenceIdentifier'] !== $identifier) {
                continue;
            }
            if ($write && (!empty($form['readOnly']) || !empty($form['invalid']))) {
                throw new \DomainException('readonly');
            }
            if (($form['fileUid'] ?? 0) > 0) {
                try {
                    $file = $this->resources->getFileObject((int)$form['fileUid']);
                    if (!$file->checkActionPermission('read') || ($write && !$file->checkActionPermission('write'))) {
                        throw new \DomainException('access');
                    }
                } catch (\Throwable) {
                    throw new \DomainException('access');
                }
            } elseif (ctype_digit($identifier)) {
                if ((new Typo3Version())->getMajorVersion() < 14 || !$GLOBALS['BE_USER']->check($write ? 'tables_modify' : 'tables_select', 'form_definition')
                    || !$this->adapter->allowedIdentifier($identifier, $this->settings())) {
                    throw new \DomainException('access');
                }
            }
            return $form;
        }
        throw new \DomainException('access');
    }
    public function load(string $identifier): array
    {
        $this->find($identifier);
        return $this->adapter->load($identifier, $this->settings());
    }
    public function targets(): array
    {
        if (!$this->importAllowed()) {
            return [];
        }
        return $this->adapter->targets($this->settings());
    }
    public function importAllowed(): bool
    {
        return Edition::pro() && (int)($GLOBALS['BE_USER']->workspace ?? 0) === 0 && ($GLOBALS['BE_USER']->isAdmin() || (string)($GLOBALS['BE_USER']->getTSConfig()['options.']['formManagerPlus.']['allowImport'] ?? '0') === '1');
    }
    public function matchFormIdentifier(string $logicalIdentifier): ?string
    {
        if (!$this->importAllowed()) {
            throw new \DomainException('access');
        }
        $matches = [];
        foreach ($this->catalog->forms(true) as $form) {
            if (($form['identifier'] ?? '') === $logicalIdentifier) {
                $matches[] = (string)$form['persistenceIdentifier'];
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) > 1) {
            throw new \DomainException('ambiguous_identifier');
        }
        return $matches[0] ?? null;
    }
    public function overwriteDefinition(string $identifier): array
    {
        if (!$this->importAllowed()) {
            throw new \DomainException('access');
        }
        $this->catalog->clear();
        $form = $this->find($identifier, true);
        if (empty($form['fileUid']) && !ctype_digit($identifier)) {
            throw new \DomainException('readonly');
        }
        $this->clearDefinitionCache($identifier);
        return $this->load($identifier);
    }
    private function clearDefinitionCache(string $identifier): void
    {
        // TYPO3 13 retains loaded definitions within the request even after save().
        if ((new Typo3Version())->getMajorVersion() === 13) {
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('runtime')->remove('ext-form-load-' . hash('xxh3', $identifier));
        }
    }
    public function overwrite(array $definition, string $identifier, string $expectedHash): string
    {
        $current = $this->overwriteDefinition($identifier);
        if (!hash_equals($expectedHash, hash('sha256', json_encode($current, JSON_THROW_ON_ERROR)))) {
            throw new \DomainException('conflict');
        }
        // Keep the logical and persistent identities; existing embeds continue to point here.
        $definition['identifier'] = $current['identifier'];
        $this->persistence->save($identifier, $definition, (new Typo3Version())->getMajorVersion() >= 14 ? [] : $this->settings());
        $this->clearDefinitionCache($identifier);
        $this->catalog->clear();
        return $identifier;
    }
    public function create(array $definition, string $targetKey): string
    {
        $target = null;
        foreach ($this->targets() as $item) {
            if ($item['key'] === $targetKey) {
                $target = $item;
            }
        }
        if (!$target) {
            throw new \DomainException('target');
        }
        $identifier = $this->adapter->create($definition, $target, $this->settings());
        $this->lifecycle->forget($identifier);
        $this->catalog->clear();
        return (string)$identifier;
    }
    public function importConflicts(array $definition): array
    {
        $findings = [];
        foreach ($this->catalog->forms() as $form) {
            if (($form['identifier'] ?? '') === ($definition['identifier'] ?? '')) {
                $findings['identifier_conflict'] = ['code' => 'identifier_conflict', 'severity' => 'warning', 'path' => 'form.identifier', 'element' => ''];
            }
            if (mb_strtolower((string)$form['name']) === mb_strtolower((string)($definition['label'] ?? ''))) {
                $findings['label_conflict'] = ['code' => 'label_conflict', 'severity' => 'warning', 'path' => 'form.label', 'element' => ''];
            }
        }
        return array_values($findings);
    }
}
