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
$operations = GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlusPro\Service\FormOperations::class);
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/contact.fmp.json'), true, 48, JSON_THROW_ON_ERROR);
$created = [];
$prefix = 'operations-qa-' . bin2hex(random_bytes(6));
try {
    foreach ($access->targets() as $target) {
        for ($i = 0; $i < 2; $i++) {
            $definition = $fixture['definition'];
            $definition['identifier'] = $prefix . '-' . count($created);
            $definition['label'] = $definition['identifier'];
            $created[] = $access->create($definition, $target['key']);
        }
    }
    verify(count($created) >= 2, 'temporary forms created through native storage');
    $source = $created[0];
    $destination = $created[1];
    $data->saveProfile($source, ['purpose' => 'Source purpose', 'responsible' => 'Test team', 'notes' => 'Test notes', 'responsible_user' => 0, 'revision' => 0]);
    reject(fn() => $operations->bulk(['identifiers' => [$source, 'not-authorized'], 'action' => 'responsible', 'responsible_user' => 0]), 'preflight rejects entire batch with an unauthorized identifier');
    verify((int)$data->profile($source)['revision'] === 1, 'rejected batch writes nothing to first form');
    reject(fn() => $operations->bulk(['identifiers' => array_fill(0, 51, $source), 'action' => 'export']), 'selection limit enforced server-side');
    reject(fn() => $operations->bulk(['identifiers' => [$source], 'action' => 'responsible', 'responsible_user' => 2147483647]), 'inaccessible responsible user rejected');
    $result = $operations->bulk(['identifiers' => $created, 'action' => 'responsible', 'responsible_user' => 0]);
    verify(count($result['succeeded']) === count($created) && !$result['failed'], 'bulk responsible assignment succeeds across native storage types');
    verify($data->profile($source)['purpose'] === 'Source purpose', 'bulk ownership keeps other metadata');
    $choices = $data->categories();
    verify(count($choices) > 0, 'visible category fixture available');
    $uid = (int)$choices[0]['uid'];
    $result = $operations->bulk(['identifiers' => $created, 'action' => 'add_categories', 'categories' => [$uid]]);
    verify(count($result['succeeded']) === count($created) && !$result['failed'], 'bulk adds categories to file and database forms');
    foreach ($created as $id) {
        verify($operations->duplicateData($id, true, false)['categories'] === [$uid], 'category assignments persisted');
    }
    $result = $operations->bulk(['identifiers' => $created, 'action' => 'remove_categories', 'categories' => [$uid]]);
    verify(count($result['succeeded']) === count($created) && !$result['failed'], 'bulk removes categories');
    foreach ($created as $id) {
        verify($operations->duplicateData($id, true, false)['categories'] === [], 'category removal persisted');
    }
    $operations->bulk(['identifiers' => [$source], 'action' => 'add_categories', 'categories' => [$uid]]);
    $copy = $operations->duplicateData($source, true, true);
    $operations->applyDuplicateData($destination, $copy);
    verify($data->profile($destination)['purpose'] === 'Source purpose' && $data->profile($destination)['notes'] === 'Test notes', 'duplicate copies selected metadata');
    verify((int)$data->profile($destination)['responsible_user'] === 0, 'duplicate copy does not replace responsible user');
    verify($operations->duplicateData($destination, true, false)['categories'] === [$uid], 'duplicate copies categories');
    verify($operations->duplicateData($source, false, false) === ['categories' => [], 'profile' => []], 'optional assignments can both be omitted');
    $export = $operations->bulk(['identifiers' => $created, 'action' => 'export']);
    verify(count($export['packages']) === count($created), 'bulk exports one package per selected form');
    foreach ($export['packages'] as $package) {
        verify(json_decode($package['json'], true)['definition']['identifier'] !== '', 'bulk package contains valid form definition');
    }
    $catalog = (new ReflectionProperty($access, 'catalog'))->getValue($access);
    $forms = $catalog->forms(true);
    $query = \SchefferWebdesign\FormManagerPlus\Service\PageQuery::parse(['storage' => 'database', 'grouped' => '0']);
    $filtered = \SchefferWebdesign\FormManagerPlus\Service\PageQuery::select($forms, $query)['forms'];
    foreach ($filtered as $form) {
        verify(ctype_digit((string)$form['persistenceIdentifier']), 'storage filter excludes file forms');
    }
    verify($controller->api($request->withMethod('GET')->withQueryParams(['op' => 'bulk']))->getStatusCode() === 405, 'bulk rejects GET');
    $unauthorized = $request->withMethod('POST')->withHeader('Content-Type', 'application/json')->withBody(new \TYPO3\CMS\Core\Http\Stream('php://temp', 'w+'));
    $unauthorized->getBody()->write(json_encode(['op' => 'bulk', 'action' => 'responsible', 'identifiers' => [$source], 'responsible_user' => 0]));
    $unauthorized->getBody()->rewind();
    $sessions = \TYPO3\CMS\Core\Session\UserSessionManager::create('BE');
    $session = $sessions->elevateToFixatedUserSession($sessions->createAnonymousSession(), (int)$GLOBALS['BE_USER']->user['uid']);
    $GLOBALS['BE_USER']->initializeUserSessionManager($sessions);
    (new ReflectionProperty($GLOBALS['BE_USER'], 'userSession'))->setValue($GLOBALS['BE_USER'], $session);
    try {
        verify($controller->api($unauthorized)->getStatusCode() === 403, 'bulk rejects missing CSRF');
        $protection = (new ReflectionProperty($controller, 'protection'))->getValue($controller);
        $token = $protection->createFromRequest($request)->generateToken('form-manager-plus', 'write');
        $body = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'w+');
        $body->write(json_encode(['op' => 'bulk', 'csrf' => $token, 'action' => 'export', 'identifiers' => $created]));
        $body->rewind();
        $response = $controller->api($request->withMethod('POST')->withBody($body));
        verify($response->getStatusCode() === 200 && $response->getHeaderLine('Content-Type') === 'application/zip', 'authorized bulk API returns ZIP');
        $path = tempnam(sys_get_temp_dir(), 'fmp-test-');
        try {
            file_put_contents($path, (string)$response->getBody());
            $zip = new \ZipArchive();
            verify($zip->open($path) === true && $zip->numFiles === count($created), 'downloaded ZIP contains every selected form');
            $zip->close();
        } finally {
            unlink($path);
        }
    } finally {
        $sessions->removeSession($session);
    }
    verify(class_exists(\ZipArchive::class), 'ZIP runtime available');
} finally {
    foreach ($created as $id) {
        if (!str_starts_with($access->load($id)['identifier'], $prefix)) {
            throw new RuntimeException('Cleanup refused');
        }
        $persistence = (new ReflectionProperty($access, 'persistence'))->getValue($access);
        $persistence->delete($id, $access->settings());
        $pool = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        foreach (['tx_formmanagerplus_profile', 'tx_formmanagerplus_personal', 'tx_formmanagerplus_history'] as $table) {
            $pool->getConnectionForTable($table)->delete($table, ['form_key' => WorkspaceData::key($id)]);
        }
        $access->clearCatalog();
    }
}
