<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

$loader = require (getenv('FMP_VENDOR') ?: '/app/vendor') . '/autoload.php';
\TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(0, \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI);
\TYPO3\CMS\Core\Core\Bootstrap::init($loader);
\TYPO3\CMS\Core\Core\Bootstrap::initializeBackendUser(\TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication::class);
\TYPO3\CMS\Core\Core\Bootstrap::initializeBackendAuthentication();
use SchefferWebdesign\FormManagerPlus\Controller\ToolsController;
use SchefferWebdesign\FormManagerPlus\Service\WorkspaceData;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$dbConfig = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
if (getenv('TYPO3_CONTEXT') !== 'Development' || ($dbConfig['driver'] ?? '') !== 'pdo_sqlite' || !str_starts_with($dbConfig['path'] ?? '', '/app/var/')) {
    throw new RuntimeException('Local demo only');
}
function verify(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    } echo "PASS $label\n";
}
function reject(callable $call, string $label): void
{
    try {
        $call();
    } catch (DomainException|InvalidArgumentException) {
        echo "PASS $label\n";
        return;
    } throw new RuntimeException($label);
}
$request = (new ServerRequest('http://127.0.0.1/typo3/'))->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE);
$GLOBALS['TYPO3_REQUEST'] = $request;
$GLOBALS['LANG'] = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en');
$controller = GeneralUtility::getContainer()->get(ToolsController::class);
$access = (new ReflectionProperty($controller, 'access'))->getValue($controller);
$transfer = GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlusPro\Service\FormTransfer::class);
$data = (new ReflectionProperty($controller, 'data'))->getValue($controller);
$configuration = (new ReflectionProperty($access, 'configuration'))->getValue($access);
if (method_exists($configuration, 'setRequest')) {
    $configuration->setRequest($request);
}
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/contact.fmp.json'), true, 48, JSON_THROW_ON_ERROR);
$owner = $GLOBALS['BE_USER']->user['uid'];
$targets = $access->targets();
verify(count($targets) > 0, 'test has authorized storage');
foreach ($targets as $target) {
    $prefix = 'wizard-qa-' . bin2hex(random_bytes(6));
    $created = null;
    $copies = [];
    $fixture['definition']['identifier'] = $prefix;
    $fixture['definition']['label'] = 'QA ' . $prefix;
    $json = json_encode($fixture, JSON_THROW_ON_ERROR);
    try {
        $preview = $transfer->preview($json, $target['key']);
        $created = $transfer->apply($preview['ticket'])['identifier'];
        $original = $access->load($created);
        verify((int)$data->profile($created)['responsible_user'] === (int)$owner, 'new imports assign their creator');
        $data->saveProfile($created, ['purpose' => 'Preserve metadata', 'responsible' => '', 'notes' => '', 'revision' => (int)$data->profile($created)['revision']]);
        $formRecord = $access->find($created);
        $choices = $data->categories();
        $categorySelection = [];
        if ($choices) {
            $categorySelection = [(int)$choices[0]['uid']];
            if (!empty($formRecord['fileUid'])) {
                $categoryService = (new ReflectionProperty($controller, 'categories'))->getValue($controller);
                $assignment = $categoryService->fileAssignment($formRecord, $choices);
                $categoryService->saveFileAssignment($formRecord, $choices, $categorySelection, $assignment['version']);
            } else {
                $data->saveCategories($created, $categorySelection, (int)$data->profile($created)['revision']);
            }
        }
        $candidate = $fixture;
        $candidate['definition']['identifier'] = $original['identifier'];
        $candidate['definition']['label'] = 'Overwrite test';
        $candidate['definition']['renderables'][0]['renderables'][0]['label'] = 'Changed field';
        $candidateJson = json_encode($candidate, JSON_THROW_ON_ERROR);
        $overwrite = $transfer->preview($candidateJson, $target['key'], '', 'overwrite');
        verify($overwrite['allowed'] && $overwrite['mode'] === 'overwrite' && $overwrite['existing'] === $created, 'overwrite preview binds the exact destination');
        verify($overwrite['definition']['identifier'] === $original['identifier'], 'preview preserves destination logical identifier');
        verify(in_array('label', $overwrite['changes']['settingsChanged'], true) && count($overwrite['changes']['changed']) > 0, 'preview reports changed settings and elements');
        verify($access->load($created) === $original, 'preview does not modify existing form');
        $GLOBALS['BE_USER']->user['uid'] = 900000009;
        try {
            reject(fn() => $transfer->apply($overwrite['ticket']), 'overwrite ticket cannot be used by another user');
        } finally {
            $GLOBALS['BE_USER']->user['uid'] = $owner;
        }
        $intervening = $original;
        $intervening['label'] = 'Intervening edit';
        $access->overwrite($intervening, $created, hash('sha256', json_encode($original, JSON_THROW_ON_ERROR)));
        reject(fn() => $transfer->apply($overwrite['ticket']), 'changed destination rejects stale overwrite preview');
        verify($access->load($created)['label'] === 'Intervening edit', 'stale ticket did not replace newer edits');
        $fresh = $transfer->preview($candidateJson, '', '', 'overwrite', $created);
        $result = $transfer->apply($fresh['ticket']);
        verify($result['identifier'] === $created && $result['mode'] === 'overwrite', 'overwrite preserves persistent identifier');
        $saved = $access->load($created);
        verify($saved['label'] === 'Overwrite test' && $saved['identifier'] === $original['identifier'], 'Core stores replacement while retaining identity');
        verify($data->profile($created)['purpose'] === 'Preserve metadata', 'metadata survives overwrite');
        if ($categorySelection) {
            $assigned = !empty($formRecord['fileUid']) ? $categoryService->fileAssignment($access->find($created), $choices)['selected'] : json_decode($data->profile($created)['categories_json'], true);
            verify($assigned === $categorySelection, 'category assignments survive overwrite');
        }
        verify($transfer->apply($fresh['ticket']) === $result, 'repeated overwrite confirmation is idempotent');
        $newPreview = $transfer->preview($candidateJson, $target['key'], '', 'create');
        $copy = $transfer->apply($newPreview['ticket'])['identifier'];
        $copies[] = $copy;
        verify($copy !== $created && $access->load($copy)['identifier'] !== $original['identifier'], 'new mode creates a distinct form despite identifier collision');
        verify($access->load($created)['label'] === 'Overwrite test', 'new mode leaves matching existing form unchanged');
        $unmatched = $candidate;
        $unmatched['definition']['identifier'] = $prefix . '-unmatched';
        reject(fn() => $transfer->preview(json_encode($unmatched, JSON_THROW_ON_ERROR), $target['key'], '', 'overwrite'), 'overwrite without matching identifier is rejected server-side');
        $persistence = (new ReflectionProperty($access, 'persistence'))->getValue($access);
        if (!ctype_digit($copy)) {
            $duplicate = $access->load($copy);
            $duplicate['identifier'] = $original['identifier'];
            $persistence->save($copy, $duplicate, $access->settings());
            reject(fn() => $transfer->preview($candidateJson, $target['key'], '', 'overwrite'), 'ambiguous identifier refuses automatic overwrite');
        }
        reject(fn() => $transfer->preview($json, $target['key'], '', 'invalid'), 'unknown import mode rejected');
    } finally {
        foreach (array_filter(array_merge([$created], $copies)) as $cleanup) {
            $created = $cleanup;
            $saved = $access->load($created);
            if (!str_starts_with($saved['identifier'], $prefix)) {
                throw new RuntimeException('Cleanup refused for non-test form');
            }
            $persistence = (new ReflectionProperty($access, 'persistence'))->getValue($access);
            $persistence->delete($created, $access->settings());
            $pool = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
            foreach (['tx_formmanagerplus_profile', 'tx_formmanagerplus_personal', 'tx_formmanagerplus_history'] as $table) {
                $pool->getConnectionForTable($table)->delete($table, ['form_key' => WorkspaceData::key($created)]);
            }
            $access->clearCatalog();
        }
    }
}
