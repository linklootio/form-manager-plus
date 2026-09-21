<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\EventListener;

use SchefferWebdesign\FormManagerPlus\Service\{AjaxFeature, FormAccess};
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\PageRenderer;

final class WorkbenchButtons
{
    public function __construct(private FormAccess $access, private UriBuilder $uris, private PageRenderer $renderer) {}
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = \SchefferWebdesign\FormManagerPlus\Compatibility\ButtonRequest::from($event);
        $route = $request?->getAttribute('route')?->getOption('_identifier');
        $expected = (new Typo3Version())->getMajorVersion() >= 14 ? 'form_editor' : 'web_FormFormbuilder.FormEditor_index';
        if ($route !== $expected || !AjaxFeature::enabled()) {
            return;
        }
        $identifier = $request->getQueryParams()['formPersistenceIdentifier'] ?? '';
        if (!is_string($identifier)) {
            return;
        }
        try {
            $form = $this->access->find($identifier);
        } catch (\DomainException) {
            return;
        }
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)->addInlineLanguageLabelFile('EXT:form_manager_plus/Resources/Private/Language/locallang.xlf', 'fmp.');
        $this->renderer->addInlineSetting('FormManagerPlus', 'enabled', true);
        $this->renderer->addInlineSetting('FormManagerPlus', 'description', [
            'pro' => \SchefferWebdesign\FormManagerPlus\Service\Edition::pro(), 'identifier' => $identifier, 'name' => $form['name'],
            'api' => (string)$this->uris->buildUriFromRoute('ajax_form_manager_plus_tools'),
            'csrf' => \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\FormProtection\FormProtectionFactory::class)->createFromRequest($request)->generateToken('form-manager-plus', 'write'),
            'language' => str_starts_with((string)$GLOBALS['LANG']->getLocale(), 'de') ? 'de' : 'en',
        ]);
        $this->renderer->addCssFile(\TYPO3\CMS\Core\Utility\PathUtility::getPublicResourceWebPath('EXT:form_manager_plus/Resources/Public/Css/editor-description.css'));
        $this->renderer->addCssFile(\TYPO3\CMS\Core\Utility\PathUtility::getPublicResourceWebPath('EXT:form_manager_plus/Resources/Public/Css/pro.css'));
    }
}
