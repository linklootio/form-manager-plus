<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use SchefferWebdesign\FormManagerPlus\Service\AjaxFeature;
use TYPO3\CMS\Form\Domain\DTO\SearchCriteria;

class DeferredFormManager14 extends \TYPO3\CMS\Form\Controller\FormManagerController
{
    use AssignCreatedForm;
    protected function createAction(string $formName, string $templatePath, string $prototypeName, string $storage, string $storageLocation): \Psr\Http\Message\ResponseInterface
    {
        return $this->assignCreatedForm(parent::createAction($formName, $templatePath, $prototypeName, $storage, $storageLocation));
    }
    protected function duplicateAction(string $formName, string $formPersistenceIdentifier, string $storage, string $storageLocation, bool $copyCategories = false, bool $copyMetadata = false): \Psr\Http\Message\ResponseInterface
    {
        $copy = AjaxFeature::enabled() && AjaxFeature::allowed()
            ? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\SchefferWebdesign\FormManagerPlus\Service\DuplicateAssignments::class)->duplicateData($formPersistenceIdentifier, $copyCategories, $copyMetadata) : null;
        return $this->assignCreatedForm(parent::duplicateAction($formName, $formPersistenceIdentifier, $storage, $storageLocation), $copy);
    }
    protected function deleteAction(string $formPersistenceIdentifier): \Psr\Http\Message\ResponseInterface
    {
        $response = parent::deleteAction($formPersistenceIdentifier);
        $payload = json_decode((string)$response->getBody(), true);
        if (($payload['response']['status'] ?? $payload['status'] ?? '') === 'success') {
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\SchefferWebdesign\FormManagerPlus\Service\FormLifecycle::class)->deleted($formPersistenceIdentifier);
        }
        return $response;
    }
    protected function getAvailableFormDefinitions(array $formSettings, SearchCriteria $searchCriteria, string $returnUrl = ''): array
    {
        return AjaxFeature::enabled() ? [] : parent::getAvailableFormDefinitions($formSettings, $searchCriteria, $returnUrl);
    }
}
