<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\EventListener;

use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use SchefferWebdesign\FormManagerPlus\Service\CategoryActions;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;

/** Category editing belongs to a specific, authorized form, never the list. */
final class FormEditorCategories
{
    public function __construct(private FormSourceInterface $source, private CategoryActions $actions, private UriBuilder $uris, private IconFactory $icons) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = \SchefferWebdesign\FormManagerPlus\Compatibility\ButtonRequest::from($event);
        if (!$request) {
            return;
        }
        $route = $request->getAttribute('route')?->getOption('_identifier');
        $editorRoute = (new Typo3Version())->getMajorVersion() >= 14 ? 'form_editor' : 'web_FormFormbuilder.FormEditor_index';
        // Assignment now lives in the editor's Metadata tab; keep Core record creation support.
        if ($route === $editorRoute && \SchefferWebdesign\FormManagerPlus\Service\AjaxFeature::enabled()) {
            return;
        }
        if (!in_array($route, [$editorRoute, 'record_edit'], true)) {
            return;
        }
        $query = $request->getQueryParams();
        $identifier = $route === $editorRoute ? ($query['formPersistenceIdentifier'] ?? '') : ($query['fmpForm'] ?? '');
        if (!$identifier && $route === 'record_edit' && is_string($query['returnUrl'] ?? null)) {
            parse_str((string)parse_url($query['returnUrl'], PHP_URL_QUERY), $returnArguments);
            $identifier = $returnArguments['formPersistenceIdentifier'] ?? '';
        }
        if (!is_string($identifier) || $identifier === '') {
            return;
        }
        if ((string)(BackendUtility::getPagesTSconfig(0)['tx_form_manager_plus.']['categories.']['enabled'] ?? '1') === '0') {
            return;
        }
        foreach ($this->source->listForms() as $form) {
            if (($form['persistenceIdentifier'] ?? '') !== $identifier) {
                continue;
            }
            $editorUrl = (string)$this->uris->buildUriFromRoute($editorRoute, ['formPersistenceIdentifier' => $identifier]);
            $categoryUrl = $this->actions->editUrl((int)($form['fileUid'] ?? 0), empty($form['invalid']) && empty($form['readOnly']), $editorUrl, $identifier);
            if ($categoryUrl === '') {
                return;
            }
            $buttons = $event->getButtons();
            $label = fn(string $key): string => $GLOBALS['LANG']->sL('LLL:EXT:form_manager_plus/Resources/Private/Language/locallang.xlf:' . $key);
            if ($route === $editorRoute) {
                $button = (new LinkButton())->setHref($categoryUrl)->setTitle($label('editorCategories'))->setShowLabelText(true)->setIcon($this->icons->getIcon('mimetypes-x-sys_category', IconSize::SMALL));
                $buttons[ButtonBar::BUTTON_POSITION_LEFT][40][] = $button;
            } else {
                // Do not expose contextual creation on an unrelated edited record.
                parse_str((string)parse_url($categoryUrl, PHP_URL_QUERY), $categoryArguments);
                if (($query['edit'] ?? null) !== ($categoryArguments['edit'] ?? null)) {
                    return;
                }
                $targets = $this->actions->creationTargets($categoryUrl);
                if (!$targets) {
                    return;
                }
                $button = (new DropDownButton())->setLabel($label('createCategory'))->setTitle($label('createCategory'))->setShowLabelText(true)->setIcon($this->icons->getIcon('actions-add', IconSize::SMALL));
                foreach ($targets as $target) {
                    $item = new DropDownItem();
                    $item->setHref($target['url'])->setLabel($label('categoryStorage') . ': ' . $target['title']);
                    $button->addItem($item);
                }
                $buttons[ButtonBar::BUTTON_POSITION_LEFT][40][] = $button;
            }
            $event->setButtons($buttons);
            return;
        }
    }
}
