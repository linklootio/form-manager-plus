<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Form\Service\DatabaseService;

/** Enriches authorized forms without copying, caching or modifying definitions. */
final class FormList
{
    public function __construct(
        private FormSourceInterface $source,
        private CategoryProvider $categoryProvider,
        private DatabaseService $databaseService,
        private UriBuilder $uriBuilder,
    ) {}

    /** @return list<array<string, mixed>> */
    public function getForms(): array
    {
        $forms = $this->source->listForms();
        $major = (new Typo3Version())->getMajorVersion();
        $categories = $this->categoryProvider->forFiles(array_map(
            static fn(array $form): int => (int)($form['fileUid'] ?? 0),
            $forms,
        ));
        // TYPO3 14 supplies reference counts from its storage adapters.
        $fileReferences = $major < 14 ? $this->databaseService->getAllReferencesForFileUid() : [];
        $pathReferences = $major < 14 ? $this->databaseService->getAllReferencesForPersistenceIdentifier() : [];
        foreach ($forms as &$form) {
            $identifier = (string)($form['persistenceIdentifier'] ?? '');
            $uid = (int)($form['fileUid'] ?? 0);
            $form['fileUid'] = $uid;
            $form['group'] = $categories[$uid] ?? '';
            $form['referenceCount'] = $major < 14
                ? (int)($fileReferences[$uid] ?? $pathReferences[$identifier] ?? 0)
                : (int)($form['referenceCount'] ?? 0);
            $form['editUrl'] = '';
            if ($identifier !== '' && empty($form['invalid']) && empty($form['readOnly'])) {
                $form['editUrl'] = (string)$this->uriBuilder->buildUriFromRoute(
                    $major >= 14 ? 'form_editor' : ($major === 11 ? 'web_FormFormbuilder' : 'web_FormFormbuilder.FormEditor_index'),
                    $major === 11
                        ? ['tx_form_web_formformbuilder' => ['action' => 'index', 'controller' => 'FormEditor', 'formPersistenceIdentifier' => $identifier]]
                        : ['formPersistenceIdentifier' => $identifier],
                );
            }
            $form['historyUrl'] = $major >= 14 && ctype_digit($identifier)
                ? (string)$this->uriBuilder->buildUriFromRoute('record_history', [
                    'element' => 'form_definition:' . $identifier,
                    'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('form_manager'),
                ]) : '';
        }
        unset($form);
        return $forms;
    }
}
