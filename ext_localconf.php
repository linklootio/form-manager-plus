<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
$major = (new Typo3Version())->getMajorVersion();
if ($major === 13) {
    ExtensionManagementUtility::addTypoScriptSetup('module.tx_form.settings.yamlConfigurations.1700 = EXT:form_manager_plus/Configuration/FormSetup.yaml');
}
if ($major >= 13) {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = \SchefferWebdesign\FormManagerPlus\Service\RecentChanges::class;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Form\Controller\FormManagerController::class]['className'] = $major >= 14
        ? \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager14::class
        : \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager13::class;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['form_manager_plus_catalog'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    ];
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['form_manager_plus_transfer'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    ];
}
