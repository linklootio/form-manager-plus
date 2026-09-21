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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$configuration = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
if (getenv('TYPO3_CONTEXT') !== 'Development' || $configuration['driver'] !== 'pdo_sqlite' || !str_starts_with($configuration['path'], '/app/var/')) {
    throw new RuntimeException('Local demo only');
}
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    } echo "PASS $message\n";
}
function denied(callable $call, string $message): void
{
    try {
        $call();
    } catch (DomainException|InvalidArgumentException) {
        echo "PASS $message\n";
        return;
    } throw new RuntimeException($message);
}
$pool = GeneralUtility::makeInstance(ConnectionPool::class);
$data = new WorkspaceData($pool);
$user = $GLOBALS['BE_USER'];
$original = $user->user;
$groups = $user->userGroupsUID;
$workspace = $user->workspace;
$id = 'pro-qa-' . bin2hex(random_bytes(8));
$key = WorkspaceData::key($id);
$owner = 900000041;
$member = 900000042;
verify(!$pool->getConnectionForTable('be_users')->count('*', 'be_users', ['uid' => $owner]) && !$pool->getConnectionForTable('be_users')->count('*', 'be_users', ['uid' => $member]), 'test identities are not real accounts');
$created = [];
try {
    $user->workspace = 0;
    $user->user = array_replace($user->user, ['uid' => $owner, 'admin' => 1, 'username' => 'qa', 'realName' => 'QA owner']);
    $group = $data->teamGroups()[0]['uid'];
    $views = $data->saveView(['name' => $id, 'group' => $group, 'state' => ['length' => 25, 'mode' => 'mine', 'responsible' => 'unassigned', 'team' => 'Support']]);
    $view = array_values(array_filter($views, fn($v) => $v['name'] === $id))[0];
    $created[] = $view['id'];
    verify($view['group'] === $group && $view['editable'] && $view['state']['team'] === 'Support', 'team view saves group and assignment filters');
    $user->user['uid'] = $member;
    $user->user['admin'] = 0;
    $user->userGroupsUID = [$group];
    $visible = array_values(array_filter($data->views(), fn($v) => $v['id'] === $view['id']));
    verify(count($visible) === 1 && !$visible[0]['editable'], 'group member can apply but not overwrite a shared view');
    denied(fn() => $data->saveView(['id' => $view['id'], 'name' => 'hijack', 'group' => $group, 'state' => ['length' => 25]]), 'shared view rejects foreign edits');
    $data->deleteView($view['id']);
    verify(count(array_filter($data->views(), fn($v) => $v['id'] === $view['id'])) === 1, 'shared view rejects foreign deletion');
    $user->userGroupsUID = [];
    verify(!array_filter($data->views(), fn($v) => $v['id'] === $view['id']), 'nonmember cannot see team view');
    denied(fn() => $data->saveView(['name' => 'forbidden', 'group' => $group, 'state' => ['length' => 25]]), 'nonmember cannot publish to group');
    $user->userGroupsUID = [$group];
    $user->workspace = 1;
    verify(!array_filter($data->views(), fn($v) => $v['id'] === $view['id']), 'shared views stay workspace-scoped');
    $user->workspace = 0;
    $user->user['uid'] = $owner;
    $user->user['admin'] = 1;
    $profile = ['purpose' => 'Initial', 'notes' => '<script>literal</script>', 'responsible' => 'Support', 'responsible_user' => 0, 'revision' => 0];
    $data->saveProfile($id, $profile);
    $history = $data->history($id);
    verify(count($history['items']) === 1 && $history['items'][0]['changes']['purpose']['after'] === 'Initial', 'metadata write records before and after');
    $profile['revision'] = 1;
    $data->saveProfile($id, $profile);
    verify(count($data->history($id)['items']) === 1, 'unchanged values do not create history noise');
    $profile['revision'] = 2;
    $profile['purpose'] = 'Updated';
    $data->saveProfile($id, $profile);
    verify($data->history($id)['items'][0]['changes']['purpose']['before'] === 'Initial', 'history captures actual previous value');
    $data->recordHistory($id, ['categories' => []], ['categories' => [2147483647]]);
    verify($data->history($id)['items'][0]['changes']['categories']['after'] === ['[restricted]'], 'history does not reveal inaccessible categories');
    $user->workspace = 1;
    verify($data->history($id)['items'] === [], 'history is workspace-scoped');
    $user->workspace = 0;
    $forms = [['persistenceIdentifier' => 'a', 'name' => 'A', 'group' => '', 'mine' => true, 'responsibleUser' => 12, 'responsible' => 'Support'], ['persistenceIdentifier' => 'b', 'name' => 'B', 'group' => '', 'mine' => false, 'responsibleUser' => 0, 'responsible' => 'Sales']];
    verify(PageQuery::select($forms, PageQuery::parse(['mode' => 'mine']))['recordsFiltered'] === 1, 'My forms filters current ownership');
    verify(PageQuery::select($forms, PageQuery::parse(['responsible' => '12', 'team' => 'support']))['recordsFiltered'] === 1, 'owner and team filters combine');
    verify(PageQuery::select($forms, PageQuery::parse(['responsible' => 'unassigned']))['forms'][0]['name'] === 'B', 'unassigned filter works');
} finally {
    foreach (['tx_formmanagerplus_profile', 'tx_formmanagerplus_personal', 'tx_formmanagerplus_history'] as $table) {
        $pool->getConnectionForTable($table)->delete($table, ['form_key' => $key]);
    }
    foreach ($created as $uid) {
        $pool->getConnectionForTable('tx_formmanagerplus_view')->delete('tx_formmanagerplus_view', ['uid' => $uid, 'be_user' => $owner]);
    }
    $user->user = $original;
    $user->workspace = $workspace;
    $user->userGroupsUID = $groups;
}
