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
use SchefferWebdesign\FormManagerPlus\Service\{PageQuery, WorkspaceData};
use SchefferWebdesign\FormManagerPlusPro\Service\{ConfigurationInspector, TransferValidator};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$configuration = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
if (getenv('TYPO3_CONTEXT') !== 'Development' || ($configuration['driver'] ?? '') !== 'pdo_sqlite' || !str_starts_with($configuration['path'] ?? '', '/app/var/')) {
    throw new RuntimeException('This fixture test only runs against local demo SQLite databases.');
}
function verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    } echo 'PASS ' . $label . PHP_EOL;
}
function rejected(callable $call, string $label): void
{
    try {
        $call();
    } catch (DomainException|InvalidArgumentException) {
        echo 'PASS ' . $label . PHP_EOL;
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $label);
}
$pool = GeneralUtility::makeInstance(ConnectionPool::class);
$data = new WorkspaceData($pool);
$originalUser = $GLOBALS['BE_USER']->user;
$originalWorkspace = $GLOBALS['BE_USER']->workspace;
$suffix = bin2hex(random_bytes(5));
$identifier = 'fmp-qa-' . $suffix;
$key = WorkspaceData::key($identifier);
$owners = [900000001, 900000002];
foreach ($owners as $uid) {
    verify(!$pool->getConnectionForTable('be_users')->count('*', 'be_users', ['uid' => $uid]), 'test identity is not a real backend account');
}
$GLOBALS['BE_USER']->workspace = 0;
$viewIds = [];
try {
    $GLOBALS['BE_USER']->user['uid'] = $owners[0];
    $profile = $data->saveProfile($identifier, ['purpose' => 'QA purpose', 'responsible' => 'QA team', 'notes' => '<script>literal notes</script>', 'revision' => 0]);
    verify((int)$profile['revision'] === 1 && $profile['purpose'] === 'QA purpose', 'profile saved with optimistic revision');
    rejected(fn() => $data->saveProfile($identifier, ['purpose' => 'lost update', 'responsible' => '', 'notes' => '', 'revision' => 0]), 'stale profile write rejected');
    verify($data->profile($identifier)['purpose'] === 'QA purpose', 'rejected update did not alter profile');
    $data->assignCreator($identifier);
    verify((int)$data->profile($identifier)['responsible_user'] === $owners[0], 'creator assigned to new form');
    $GLOBALS['BE_USER']->user['uid'] = $owners[1];
    $data->assignCreator($identifier);
    verify((int)$data->profile($identifier)['responsible_user'] === $owners[0], 'existing responsibility is never overwritten');
    $GLOBALS['BE_USER']->user['uid'] = $owners[0];
    $data->favorite($identifier, true);
    $data->edited($identifier);
    $forms = [['persistenceIdentifier' => $identifier, 'name' => 'QA', 'fileUid' => 0, 'group' => '', 'referenceCount' => 0]];
    $enriched = $data->enrich($forms);
    verify($enriched[0]['favorite'] && $enriched[0]['lastEdited'] > 0, 'favorite and last edit persisted');
    $views = $data->saveView(['name' => 'QA ' . $suffix, 'state' => ['search' => 'QA', 'category' => '', 'mode' => 'favorites', 'length' => 10, 'start' => 0, 'sort' => 'name', 'direction' => 'asc', 'csrf' => 'must-not-be-stored']]);
    $view = array_values(array_filter($views, static fn($v) => $v['name'] === 'QA ' . $suffix))[0];
    $viewIds[] = $view['id'];
    verify($view['state']['mode'] === 'favorites' && !isset($view['state']['csrf']), 'named view stores only whitelisted table state');
    rejected(fn() => $data->saveView(['name' => 'QA ' . $suffix, 'state' => ['length' => 10]]), 'duplicate view name rejected');
    $GLOBALS['BE_USER']->user['uid'] = $owners[1];
    verify(!$data->enrich($forms)[0]['favorite'] && !$data->enrich($forms)[0]['lastEdited'] && $data->views() === [], 'personal data isolated between users');
    rejected(fn() => $data->saveView(['id' => $view['id'], 'name' => 'hijack', 'state' => ['length' => 10]]), 'foreign view cannot be edited');
    $data->deleteView($view['id']);
    $GLOBALS['BE_USER']->user['uid'] = $owners[0];
    verify(count($data->views()) === 1, 'foreign deletion did not remove owner view');
    $GLOBALS['BE_USER']->workspace = 1;
    verify(!$data->enrich($forms)[0]['favorite'] && $data->views() === [], 'personal data isolated by workspace');
    $GLOBALS['BE_USER']->workspace = 0;
    $categories = $data->categories();
    if ($categories) {
        $profile = $data->saveCategories($identifier, [(int)$categories[0]['uid']], 2);
        verify((int)$profile['revision'] === 3, 'database category assignment updates revision');
        rejected(fn() => $data->saveCategories($identifier, [2147483647], 2), 'inaccessible category assignment rejected');
    }
    verify(PageQuery::select($enriched, PageQuery::parse(['mode' => 'favorites']))['recordsFiltered'] === 1, 'favorite filter');
    verify(PageQuery::select($enriched, PageQuery::parse(['mode' => 'recent', 'sort' => 'lastEdited', 'direction' => 'desc']))['recordsFiltered'] === 1, 'recent filter');
    $signals = $forms;
    $signals[0]['modifiedAt'] = time() - 200 * 86400;
    verify(PageQuery::select($signals, PageQuery::parse(['mode' => 'stale']))['recordsFiltered'] === 1, 'stale form filter');
    verify(PageQuery::parse(['mode' => 'issues'])['mode'] === '', 'removed inspection filter falls back to all forms');
    verify(PageQuery::select($signals, PageQuery::parse(['mode' => 'unreferenced']))['recordsFiltered'] === 1, 'unreferenced is separate from unused assertion');
} finally {
    foreach (['tx_formmanagerplus_profile', 'tx_formmanagerplus_personal', 'tx_formmanagerplus_history'] as $table) {
        $pool->getConnectionForTable($table)->delete($table, ['form_key' => $key]);
    }
    foreach ($viewIds as $id) {
        $pool->getConnectionForTable('tx_formmanagerplus_view')->delete('tx_formmanagerplus_view', ['uid' => $id, 'be_user' => $owners[0]]);
    }
    $GLOBALS['BE_USER']->user = $originalUser;
    $GLOBALS['BE_USER']->workspace = $originalWorkspace;
}

$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/contact.fmp.json'), true, 48, JSON_THROW_ON_ERROR);
$settings = ['prototypes' => ['standard' => [
    'formElementsDefinition' => array_fill_keys(['Form', 'Page', 'Text', 'Email', 'Textarea'], []),
    'validatorsDefinition' => ['NotEmpty' => []],
    'finishersDefinition' => array_fill_keys(['Confirmation', 'EmailToReceiver', 'EmailToSender', 'SaveToDatabase', 'Redirect'], []),
]]];
$inspector = new ConfigurationInspector();
$validator = new TransferValidator($inspector);
verify($validator->decode(json_encode($fixture), $settings)['allowed'], 'valid transfer package accepted');
$broken = $fixture;
$broken['definition']['renderables'][0]['renderables'][1]['identifier'] = 'name';
verify(!$validator->decode(json_encode($broken), $settings)['allowed'], 'duplicate field identifiers blocked');
$broken = $fixture;
$broken['definition']['prototypeName'] = 'missing';
verify(!$validator->decode(json_encode($broken), $settings)['allowed'], 'missing prototype blocked');
$broken = $fixture;
$broken['definition']['implementationClassName'] = 'InjectedClass';
verify(!$validator->decode(json_encode($broken), $settings)['allowed'], 'implementation injection blocked');
$broken = $fixture;
$broken['definition']['finishers'] = [['identifier' => 'SaveToDatabase', 'options' => []]];
verify(!$validator->decode(json_encode($broken), $settings)['allowed'], 'restricted database finisher blocked');
$broken = $fixture;
$broken['definition']['properties']['apiKey'] = 'private-test-value';
verify(!$validator->decode(json_encode($broken), $settings)['allowed'], 'credential-like field blocks transfer');
$broken = $fixture;
$broken['definition']['renderables'][0]['renderables'][0]['label'] = '<script>alert(1)</script>Name';
$result = $validator->decode(json_encode($broken), $settings);
verify(!str_contains($result['definition']['renderables'][0]['renderables'][0]['label'], '<script') && in_array('html_sanitized', array_column($result['findings'], 'code'), true), 'HTML sanitized and disclosed in preview');
rejected(fn() => $validator->decode('{invalid', $settings), 'invalid JSON rejected');
rejected(fn() => $validator->decode(str_repeat('x', 2 * 1024 * 1024 + 1), $settings), 'oversized transfer rejected');
$broken = $fixture;
$broken['definition']['finishers'] = [['identifier' => 'EmailToReceiver', 'options' => []]];
$findings = $inspector->inspect($broken['definition'], $settings);
verify(in_array('missing_recipients', array_column($findings, 'code'), true), 'missing recipients identified');
verify($inspector->inspect(['invalid' => true], $settings)[0]['severity'] === 'error', 'unreadable Core definition is an error');
echo "Workspace and transfer safety checks complete.\n";
