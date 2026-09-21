<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class RowPresenter
{
    public function __construct(private UriBuilder $uris, private IconFactory $icons, private ResourceFactory $resources) {}
    public function present(array $form): ?array
    {
        $major = (new Typo3Version())->getMajorVersion();
        $identifier = (string)$form['persistenceIdentifier'];
        if (($form['fileUid'] ?? 0) > 0) {
            try {
                $file = $this->resources->getFileObject((int)$form['fileUid']);
                if (!$file->checkActionPermission('read')) {
                    return null;
                }
                if (!$file->checkActionPermission('write')) {
                    $form['readOnly'] = true;
                }
                if (!$file->checkActionPermission('delete')) {
                    $form['removable'] = false;
                }
            } catch (\TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException) {
                return null;
            }
        }
        $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = static fn(string $key): string => $GLOBALS['LANG']->sL('LLL:EXT:form_manager_plus/Resources/Private/Language/locallang.xlf:' . $key);
        $edit = empty($form['invalid']) && empty($form['readOnly'])
            ? (string)$this->uris->buildUriFromRoute($major >= 14 ? 'form_editor' : 'web_FormFormbuilder.FormEditor_index', ['formPersistenceIdentifier' => $identifier]) : '';
        $de = str_starts_with((string)$GLOBALS['LANG']->getLocale(), 'de');
        $menuLabels = ['profile' => \SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_9eddf573cb50'), 'duplicate' => \SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_02cdaabfca80'), 'delete' => \SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_e2d0a54968ea'), 'export' => \SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_3664895579f0')];
        $action = function (string $key, string $icon, string $href = '#') use ($e, $label, $menuLabels): string {
            $title = $menuLabels[$key] ?? $label($key);
            return '<a class="btn btn-default btn-sm" href="' . $e($href) . '" title="' . $e($title) . '" aria-label="' . $e($title) . '"'
                . ($key === 'export' ? ' data-fmp-action="export"' : '')
                . ($key === 'export' && !Edition::pro() ? ' data-fmp-pro-preview="1"' : '')
                . ($href === '#' ? ' data-fmp-action="' . ['duplicate' => 'duplicateForm', 'delete' => 'removeForm'][$key] . '"' : '') . '>'
                . $this->icons->getIcon($icon, IconSize::SMALL)->render()
                . ($key === 'export' ? '<span class="fmp-action-label">' . $e($title) . '</span><span class="fmp-pro-badge">PRO</span>' : '') . '</a>';
        };
        $editAction = $edit ? $action('edit', 'actions-open', $edit) : '';
        $actions = '';
        $tools = (string)$this->uris->buildUriFromRoute('form_manager_plus_tools', ['form' => $identifier]);
        $actions .= $action('profile', 'actions-document-info', $edit ? (string)$this->uris->buildUriFromRoute($major >= 14 ? 'form_editor' : 'web_FormFormbuilder.FormEditor_index', ['formPersistenceIdentifier' => $identifier, 'fmpDescription' => 1]) : $tools);
        if (empty($form['invalid'])) {
            $actions .= $action('duplicate', 'actions-duplicate');
        }
        if (empty($form['invalid'])) {
            $actions .= $action('export', 'actions-download', Edition::pro() ? (string)$this->uris->buildUriFromRoute('ajax_form_manager_plus_tools', ['op' => 'export', 'identifier' => $identifier]) : 'https://typo3.linkloot.io/');
        }
        $count = (int)($form['referenceCount'] ?? 0);
        if (!empty($form['removable']) && empty($form['readOnly']) && empty($form['invalid']) && $count === 0) {
            $actions .= $action('delete', 'actions-edit-delete');
        }
        if ($major >= 14 && ctype_digit($identifier)) {
            $actions .= $action('history', 'actions-document-history-open', (string)$this->uris->buildUriFromRoute('record_history', ['element' => 'form_definition:' . $identifier, 'returnUrl' => (string)$this->uris->buildUriFromRoute('form_manager')]));
        }
        $references = $count ? '<a href="#" class="fmp-reference" data-fmp-action="showReferences" title="' . $e($label('references')) . '">' . $this->icons->getIcon('actions-link', IconSize::SMALL)->render() . '<span>' . $count . ' ' . $e($label('references')) . '</span></a>' : '';
        $actions = $editAction . '<details class="fmp-row-menu"><summary aria-label="' . $e($label('actions')) . '" title="' . $e($label('actions')) . '">' . $this->icons->getIcon('actions-menu-alternative', IconSize::SMALL)->render() . '</summary><div class="fmp-row-popover">' . $actions . $references . '</div></details>';
        $name = $edit ? '<a href="' . $e($edit) . '" title="' . $e($identifier) . '">' . $e($form['name']) . '</a>' : $e($form['name']);
        $favoriteLabel = $label(!empty($form['favorite']) ? 'unfavorite' : 'favorite');
        $star = '<button type="button" class="fmp-star" data-fmp-favorite aria-pressed="' . (!empty($form['favorite']) ? 'true' : 'false') . '" title="' . $e($favoriteLabel) . '" aria-label="' . $e($favoriteLabel) . '">' . $this->icons->getIcon('actions-star', IconSize::SMALL)->render() . '</button>';
        foreach (['invalid', 'duplicateIdentifier', 'readOnly'] as $status) {
            if (!empty($form[$status])) {
                $name .= '<span class="fmp-status">' . $e($label($status)) . '</span>';
            }
        }
        $de = str_starts_with((string)$GLOBALS['LANG']->getLocale(), 'de');
        $categoryHtml = [];
        foreach ($form['categoryItems'] ?? [] as $item) {
            $item = CategoryStyle::item(['uid' => $item['uid'], 'title' => $item['title'], 'tx_fmp_icon' => $item['icon'], 'tx_fmp_color' => $item['color']]);
            $style = $item['color'] ? ' data-fmp-color="' . $e($item['color']) . '" data-fmp-ink="' . $e($item['ink']) . '"' : '';
            $categoryHtml[] = '<button type="button" class="fmp-category-label fmp-category-filter" data-fmp-category="id:' . (int)$item['uid'] . '" title="' . $e((\SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_afc35ca904ae')) . $item['title']) . '"><span class="fmp-category-mark"' . $style . '>' . $this->icons->getIcon('actions-' . $item['icon'], IconSize::SMALL)->render() . '</span><span>' . $e($item['title']) . '</span></button>';
        }
        return ['selectable' => empty($form['invalid']), 'star' => $star, 'purpose' => $e($form['purpose'] ?? '') ?: '<span class="fmp-empty-value">—</span>',
            'category' => $categoryHtml ? implode('', $categoryHtml) : ($e($form['group'] ?? '') ?: '<span class="fmp-empty-value">' . (\SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_92114e3e22d5')) . '</span>'),
            'modified' => !empty($form['modifiedAt']) ? '<time datetime="' . gmdate('c', $form['modifiedAt']) . '">' . gmdate(\SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_268135f9291a'), $form['modifiedAt']) . '</time>' : '—',
            'uid' => $form['fileUid'] ?: '—', 'name' => $name, 'location' => '<code>' . $e($identifier) . '</code>',
            'references' => $count ? '<a href="#" class="fmp-reference-count" data-fmp-action="showReferences" title="' . (\SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_8d0ce2baadae')) . '" aria-label="' . $e($form['name']) . ': ' . $count . ' ' . $e($label('references')) . '">' . $this->icons->getIcon('actions-link', IconSize::SMALL)->render() . '<span>' . $count . '</span></a>' : '<span class="fmp-reference-zero" title="' . (\SchefferWebdesign\FormManagerPlus\Service\UiText::get('php_5a574d47bb6a')) . '">0</span>',
            'actions' => $actions, 'group' => (string)($form['group'] ?? ''), 'formName' => (string)$form['name'], 'identifier' => $identifier, 'favorite' => !empty($form['favorite'])];
    }
}
