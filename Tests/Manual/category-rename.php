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
use SchefferWebdesign\FormManagerPlus\Service\CategoryActions;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

if (getenv('TYPO3_CONTEXT') !== 'Development' || ($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] ?? '') !== 'pdo_sqlite') {
    exit(1);
}
$db = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_category');
$service = new CategoryActions(GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class), GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class));
$original = $GLOBALS['BE_USER']->user;
$groups = $GLOBALS['BE_USER']->groupData;
$name = 'Category rename QA ' . bin2hex(random_bytes(6));
$db->insert('sys_category', ['pid' => 1, 'title' => $name, 'deleted' => 0]);
$uid = (int)$db->lastInsertId();
function verify(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    } echo "PASS $label\n";
}
try {
    $GLOBALS['BE_USER']->user['admin'] = 1;
    verify($service->canRename($uid), 'authorized category can be renamed');
    $style = $service->appearance($uid, 'calendar', '#7c3aed');
    verify($style['icon'] === 'calendar' && $style['color'] === '#7c3aed', 'inline appearance saves via Core DataHandler');
    foreach ([['../../bad', '#ffffff'], ['folder', 'red;position:fixed']] as [$badIcon, $badColor]) {
        try {
            $service->appearance($uid, $badIcon, $badColor);
            throw new RuntimeException('Unsafe style accepted');
        } catch (InvalidArgumentException) {
            echo "PASS unsafe inline appearance rejected\n";
        }
    }
    verify($service->rename($uid, $name . ' new', $name) === $name . ' new', 'Core DataHandler saves the category name');
    foreach ([['', $name . ' new'], [str_repeat('x', 256), $name . ' new'], ['Lost update', $name]] as [$title, $previous]) {
        try {
            $service->rename($uid, $title, $previous);
            throw new RuntimeException('Expected rejection');
        } catch (InvalidArgumentException|DomainException) {
            echo "PASS empty, oversized or stale name rejected\n";
        }
    }
    $GLOBALS['BE_USER']->user['admin'] = 0;
    $GLOBALS['BE_USER']->groupData['tables_modify'] = '';
    verify(!$service->canRename($uid), 'read-only category has no rename control');
    try {
        $service->appearance($uid, 'folder', '#ffffff');
        throw new RuntimeException('Appearance permission bypass');
    } catch (DomainException) {
        echo "PASS unauthorized inline appearance rejected\n";
    }
    try {
        $service->rename($uid, 'Forbidden', $name . ' new');
        throw new RuntimeException('Permission bypass');
    } catch (DomainException) {
        echo "PASS unauthorized mutation rejected\n";
    }
} finally {
    $db->delete('sys_category', ['uid' => $uid]);
    $GLOBALS['BE_USER']->user = $original;
    $GLOBALS['BE_USER']->groupData = $groups;
}
