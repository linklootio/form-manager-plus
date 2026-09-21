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
use SchefferWebdesign\FormManagerPlusPro\Service\{ConfigurationInspector, TransferValidator};
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$dbConfig = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
if (getenv('TYPO3_CONTEXT') !== 'Development' || ($dbConfig['driver'] ?? '') !== 'pdo_sqlite' || !str_starts_with($dbConfig['path'] ?? '', '/app/var/')) {
    throw new RuntimeException('Local demo test only');
}
function verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    } echo 'PASS ' . $label . PHP_EOL;
}
$major = (new Typo3Version())->getMajorVersion();
$request = (new ServerRequest('http://127.0.0.1:' . ($major === 13 ? 13130 : 14140) . '/typo3/'))->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE);
$GLOBALS['TYPO3_REQUEST'] = $request;
$GLOBALS['LANG'] = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en');
$controller = GeneralUtility::getContainer()->get(ToolsController::class);
verify($controller->api($request->withMethod('GET')->withQueryParams(['op' => 'editor_categories_save']))->getStatusCode() === 405, 'category writes reject GET requests');
verify($controller->api($request->withMethod('GET')->withQueryParams(['op' => 'history', 'identifier' => 'not-an-authorized-form']))->getStatusCode() === 403, 'history rejects an unauthorized form');
$access = (new ReflectionProperty($controller, 'access'))->getValue($controller);
$transfer = GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlusPro\Service\FormTransfer::class);
$configuration = (new ReflectionProperty($access, 'configuration'))->getValue($access);
if (method_exists($configuration, 'setRequest')) {
    $configuration->setRequest($request);
}
$settings = $access->settings();
verify(isset($settings['prototypes']['standard']['formElementsDefinition']['Text']), 'real Core prototype configuration loaded');
$catalog = (new ReflectionProperty($access, 'catalog'))->getValue($access);
$forms = $catalog->forms(true);
verify(count($forms) > 0, 'Core authorized forms available');
$source = null;
foreach ($forms as $form) {
    if (!empty($form['fileUid']) && empty($form['invalid'])) {
        $source = $form;
        break;
    }
}
verify((bool)$source, 'file form fixture found');
$categoryService = (new ReflectionProperty($controller, 'categories'))->getValue($controller);
$workspaceData = (new ReflectionProperty($controller, 'data'))->getValue($controller);
$categoryChoices = $workspaceData->categories();
$originalAssignment = $categoryService->fileAssignment($source, $categoryChoices);
verify($originalAssignment['editable'], 'authorized file category assignment is editable');
verify(!$categoryService->fileAssignment(array_replace($source, ['readOnly' => true]), $categoryChoices)['editable'], 'read-only assignment remains read-only');
$changedAssignment = null;
try {
    $candidate = (int)$categoryChoices[0]['uid'];
    $selection = in_array($candidate, $originalAssignment['selected'], true)
        ? array_values(array_diff($originalAssignment['selected'], [$candidate]))
        : array_merge($originalAssignment['selected'], [$candidate]);
    $changedAssignment = $categoryService->saveFileAssignment($source, $categoryChoices, $selection, $originalAssignment['version']);
    sort($selection);
    $actual = $changedAssignment['selected'];
    sort($actual);
    verify($actual === $selection, 'Core DataHandler persists file category chip selection');
    try {
        $categoryService->saveFileAssignment($source, $categoryChoices, $originalAssignment['selected'], $originalAssignment['version']);
        verify(false, 'stale category write rejected');
    } catch (\DomainException $error) {
        verify($error->getMessage() === 'conflict', 'stale category write rejected');
    }
    try {
        $categoryService->saveFileAssignment($source, $categoryChoices, [2147483647], $changedAssignment['version']);
        verify(false, 'inaccessible category rejected');
    } catch (\DomainException $error) {
        verify($error->getMessage() === 'access', 'inaccessible category rejected');
    }
} finally {
    if ($changedAssignment) {
        $categoryService->saveFileAssignment($source, $categoryChoices, $originalAssignment['selected'], $changedAssignment['version']);
    }
}
$package = $transfer->export($source['persistenceIdentifier']);
verify($package['format'] === 'form-manager-plus' && !empty($package['definition']['renderables']), 'real Core form export includes definition only');
verify(!isset($package['profile'], $package['submissions']), 'export does not contain profiles or submissions');
$validator = new TransferValidator(new ConfigurationInspector());
$validation = $validator->decode(json_encode($package, JSON_THROW_ON_ERROR), $settings);
verify($validation['allowed'], 'exported form is valid for round-trip import');
$targets = $access->targets();
verify(count($targets) > 0, 'Core-authorized import destinations available');
$preview = $transfer->preview(json_encode($package, JSON_THROW_ON_ERROR), $targets[0]['key']);
verify($preview['allowed'] && strlen($preview['ticket']) === 48, 'import preview creates bounded approval ticket');
verify(in_array('identifier_conflict', array_column($preview['findings'], 'code'), true), 'preview identifies existing identifier without overwriting');
$owner = $GLOBALS['BE_USER']->user['uid'];
$GLOBALS['BE_USER']->user['uid'] = 900000009;
try {
    $transfer->apply($preview['ticket']);
    throw new RuntimeException('Cross-user ticket accepted');
} catch (DomainException $e) {
    verify($e->getMessage() === 'expired', 'another user cannot apply the import ticket');
} finally {
    $GLOBALS['BE_USER']->user['uid'] = $owner;
}
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/contact.fmp.json'), true, 48, JSON_THROW_ON_ERROR);
$prefix = 'fmp-qa-import-' . bin2hex(random_bytes(5));
$fixture['definition']['identifier'] = $prefix;
$fixture['definition']['label'] = 'QA import ' . $prefix;
$candidate = $transfer->preview(json_encode($fixture, JSON_THROW_ON_ERROR), $targets[0]['key']);
$created = null;
try {
    $created = $transfer->apply($candidate['ticket']);
    $again = $transfer->apply($candidate['ticket']);
    verify($again['identifier'] === $created['identifier'], 'repeated confirmation returns the same imported form');
    $imported = $access->load($created['identifier']);
    verify(str_starts_with($imported['identifier'], $prefix) && !empty($imported['renderables']), 'Core persisted a readable new form');
} finally {
    if ($created) {
        $imported = $access->load($created['identifier']);
        if (!str_starts_with($imported['identifier'], $prefix)) {
            throw new RuntimeException('Refusing to clean up a non-test form');
        }
        $persistence = (new ReflectionProperty($access, 'persistence'))->getValue($access);
        $persistence->delete($created['identifier'], $access->settings());
        $pool = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $pool->getConnectionForTable('tx_formmanagerplus_personal')->delete('tx_formmanagerplus_personal', [
            'form_key' => \SchefferWebdesign\FormManagerPlus\Service\WorkspaceData::key($created['identifier']),
            'be_user' => (int)$owner, 'workspace' => (int)$GLOBALS['BE_USER']->workspace,
        ]);
        foreach (['tx_formmanagerplus_profile', 'tx_formmanagerplus_history'] as $table) {
            $pool->getConnectionForTable($table)->delete($table, ['form_key' => \SchefferWebdesign\FormManagerPlus\Service\WorkspaceData::key($created['identifier'])]);
        }
        $catalog->clear();
    }
}
$body = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'r+');
$body->write(json_encode(['op' => 'favorite', 'identifier' => $source['persistenceIdentifier'], 'favorite' => true, 'csrf' => 'invalid']));
$body->rewind();
$sessions = \TYPO3\CMS\Core\Session\UserSessionManager::create('BE');
$session = $sessions->elevateToFixatedUserSession($sessions->createAnonymousSession(), (int)$GLOBALS['BE_USER']->user['uid']);
$GLOBALS['BE_USER']->initializeUserSessionManager($sessions);
(new ReflectionProperty($GLOBALS['BE_USER'], 'userSession'))->setValue($GLOBALS['BE_USER'], $session);
try {
    $invalidCsrf = $controller->api($request->withMethod('POST')->withBody($body));
    verify($invalidCsrf->getStatusCode() === 403, 'mutation without valid CSRF rejected');
} finally {
    $sessions->removeSession($session);
}
echo "Core integration checks complete; the uniquely named test import was removed through Core.\n";
