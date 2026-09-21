<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Compatibility;

use Psr\Http\Message\ResponseInterface;
use SchefferWebdesign\FormManagerPlus\Service\{AjaxFeature, WorkspaceData};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

trait AssignCreatedForm
{
    private function assignCreatedForm(ResponseInterface $response, ?array $copy = null): ResponseInterface
    {
        if (!AjaxFeature::enabled() || !AjaxFeature::allowed() || $response->getStatusCode() !== 200 || (int)($GLOBALS['BE_USER']->user['uid'] ?? 0) < 1) {
            return $response;
        }
        $payload = json_decode((string)$response->getBody(), true);
        $result = $payload['response'] ?? $payload;
        if (($result['status'] ?? '') !== 'success' || !is_string($result['url'] ?? null)) {
            return $response;
        }
        // Use only the successful Core response, never the submitted source identifier.
        parse_str((string)parse_url($result['url'], PHP_URL_QUERY), $query);
        $identifier = $query['formPersistenceIdentifier'] ?? null;
        if (is_string($identifier) && $identifier !== '' && strlen($identifier) <= 1024) {
            GeneralUtility::makeInstance(\SchefferWebdesign\FormManagerPlus\Service\FormLifecycle::class)->forget($identifier);
            GeneralUtility::makeInstance(WorkspaceData::class, GeneralUtility::makeInstance(ConnectionPool::class))->assignCreator($identifier);
            if ($copy !== null) {
                try {
                    GeneralUtility::makeInstance(\SchefferWebdesign\FormManagerPlus\Service\DuplicateAssignments::class)->applyDuplicateData($identifier, $copy);
                } catch (\Throwable $exception) {
                    GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__)->warning('Copy created, optional assignments could not be applied', ['exception' => $exception]);
                    if (isset($payload['response'])) {
                        $payload['response']['copyWarning'] = true;
                    } else {
                        $payload['copyWarning'] = true;
                    }
                    return new \TYPO3\CMS\Core\Http\JsonResponse($payload);
                }
            }
        }
        return $response;
    }
}
