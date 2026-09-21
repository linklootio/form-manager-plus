<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\ViewHelpers;

use SchefferWebdesign\FormManagerPlus\Service\AjaxFeature;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class TableConfigViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;
    private UriBuilder $uris;
    public function injectUriBuilder(UriBuilder $uris): void
    {
        $this->uris = $uris;
    }
    public function render(): array
    {
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)->addInlineLanguageLabelFile('EXT:form_manager_plus/Resources/Private/Language/locallang.xlf', 'fmp.');
        if (\SchefferWebdesign\FormManagerPlus\Service\Edition::pro()) {
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)->loadJavaScriptModule('@scheffer/form-manager-plus-pro/bulk-selection.js');
        }
        $major = (new Typo3Version())->getMajorVersion();
        if ($major < 13 || !AjaxFeature::enabled()) {
            return ['enabled' => false];
        }
        $factory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconFactory::class);
        $icons = [];
        foreach (['list', 'star', 'clock', 'folder', 'document-info', 'bell', 'filter', 'plus', 'refresh', 'options', 'search', 'menu', 'exchange', 'arrow-up-alt', 'arrow-down-alt'] as $name) {
            $icons[$name] = $factory->getIcon('actions-' . $name, \TYPO3\CMS\Core\Imaging\IconSize::SMALL)->render();
        }
        foreach (\SchefferWebdesign\FormManagerPlus\Service\CategoryStyle::ICONS as $name) {
            $icons[$name] = $factory->getIcon('actions-' . $name, \TYPO3\CMS\Core\Imaging\IconSize::SMALL)->render();
        }
        $icons['open'] = $factory->getIcon('actions-open', \TYPO3\CMS\Core\Imaging\IconSize::SMALL)->render();
        return [
            'icons' => $icons,
            'extensionVersion' => \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getExtensionVersion('form_manager_plus'),
            'pro' => \SchefferWebdesign\FormManagerPlus\Service\Edition::pro(), 'enabled' => true, 'major' => $major,
            'url' => (string)$this->uris->buildUriFromRoute('ajax_form_manager_plus_list'),
            'toolsApi' => (string)$this->uris->buildUriFromRoute('ajax_form_manager_plus_tools'),
            'toolsPage' => (string)$this->uris->buildUriFromRoute('form_manager_plus_tools', ['tab' => 'transfer']),
            'csrf' => \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\FormProtection\FormProtectionFactory::class)->createFromRequest($GLOBALS['TYPO3_REQUEST'])->generateToken('form-manager-plus', 'write'),
            'stateKey' => 'fmp:datatables:v1:' . hash('sha256', \TYPO3\CMS\Core\Core\Environment::getProjectPath() . ':' . (string)($GLOBALS['BE_USER']->user['uid'] ?? '') . ':' . $GLOBALS['BE_USER']->workspace . ':' . $major),
            'language' => str_starts_with((string)$GLOBALS['LANG']->getLocale(), 'de') ? 'de' : 'en',
        ];
    }
}
