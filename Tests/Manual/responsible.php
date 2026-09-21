<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

$loader = require '/app/vendor/autoload.php';
\TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(0, \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI);
\TYPO3\CMS\Core\Core\Bootstrap::init($loader);
\TYPO3\CMS\Core\Core\Bootstrap::initializeBackendUser(\TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication::class);
\TYPO3\CMS\Core\Core\Bootstrap::initializeBackendAuthentication();
use SchefferWebdesign\FormManagerPlus\Service\WorkspaceData;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

if (getenv('TYPO3_CONTEXT') !== 'Development' || ($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] ?? '') !== 'pdo_sqlite') {
    exit(1);
}
function verify(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    } echo "PASS $label\n";
}
$pool = GeneralUtility::makeInstance(ConnectionPool::class);
$users = $pool->getConnectionForTable('be_users');
$data = new WorkspaceData($pool);
$original = $GLOBALS['BE_USER']->user;
$originalGroup = $GLOBALS['BE_USER']->groupData;
$identifier = 'responsible-qa-' . bin2hex(random_bytes(8));
$fixtureId = null;
try {
    $users->insert('be_users', ['username' => $identifier, 'realName' => 'Assignment QA', 'pid' => 0, 'disable' => 0, 'deleted' => 0, 'admin' => 0, 'starttime' => 0, 'endtime' => 0]);
    $fixtureId = (int)$users->lastInsertId();
    $demo = $users->select(['*'], 'be_users', ['username' => 'demo'])->fetchAssociative();
    $GLOBALS['BE_USER']->user = $demo;
    $GLOBALS['BE_USER']->groupData['tables_select'] = '';
    $choices = $data->responsibleUsers();
    verify(array_column($choices, 'uid') === [(int)$demo['uid']], 'non-admin without user-list permission sees only self');
    verify(array_keys($choices[0]) === ['uid', 'label'], 'choices expose no credentials or email');
    $input = ['purpose' => 'QA', 'responsible' => 'Existing team', 'notes' => '', 'revision' => 0, 'responsible_user' => (int)$demo['uid']];
    $saved = $data->saveProfile($identifier, $input);
    verify((int)$saved['responsible_user'] === (int)$demo['uid'] && $saved['responsible'] === 'Existing team', 'user relation saved without losing legacy team text');
    foreach ([$fixtureId, 2147483647, '1', -1] as $invalid) {
        try {
            $data->saveProfile($identifier, array_replace($input, ['revision' => 1, 'responsible_user' => $invalid]));
            throw new RuntimeException('Invalid assignee accepted');
        } catch (DomainException|InvalidArgumentException) {
            echo "PASS invalid or inaccessible assignee rejected\n";
        }
    }
    $GLOBALS['BE_USER']->user['admin'] = 1;
    verify(in_array($fixtureId, array_column($data->responsibleUsers(), 'uid'), true), 'admin can select other active backend users');
    $data->saveProfile($identifier, array_replace($input, ['revision' => 1, 'responsible_user' => $fixtureId]));
    $users->update('be_users', ['disable' => 1], ['uid' => $fixtureId]);
    verify(!in_array($fixtureId, array_column($data->responsibleUsers(), 'uid'), true), 'disabled user excluded from choices');
    $data->saveProfile($identifier, array_replace($input, ['revision' => 2, 'responsible_user' => $fixtureId]));
    $cleared = $data->saveProfile($identifier, array_replace($input, ['revision' => 3, 'responsible_user' => 0]));
    verify((int)$cleared['responsible_user'] === 0, 'existing unavailable relation can be retained or explicitly cleared');
} finally {
    $pool->getConnectionForTable('tx_formmanagerplus_profile')->delete('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key($identifier)]);
    if ($fixtureId) {
        $users->delete('be_users', ['uid' => $fixtureId, 'username' => $identifier]);
    }
    $GLOBALS['BE_USER']->user = $original;
    $GLOBALS['BE_USER']->groupData = $originalGroup;
}
