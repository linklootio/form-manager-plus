<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SchefferWebdesign\FormManagerPlus\Service\{AjaxFeature, CategoryActions, Edition, FormAccess, ProOperationsInterface, WorkspaceData};
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Information\Typo3Version;

final class ToolsController
{
    public function __construct(
        private FormAccess $access,
        private WorkspaceData $data,
        private FormProtectionFactory $protection,
        private UriBuilder $uris,
        private ModuleTemplateFactory $templates,
        private CategoryActions $categories,
        private ProOperationsInterface $pro
    ) {}
    private function guard(): void
    {
        if (!AjaxFeature::allowed() || !AjaxFeature::enabled()) {
            throw new \DomainException('access');
        }
    }
    private function references(string $identifier): array
    {
        $backendUser = $GLOBALS['BE_USER'];
        $database = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Form\Service\DatabaseService::class);
        $returnUrl = (string)$this->uris->buildUriFromRoute((new Typo3Version())->getMajorVersion() >= 14 ? 'form_manager' : 'web_FormFormbuilder');
        $references = [];
        foreach ($database->getReferencesByPersistenceIdentifier($identifier) as $reference) {
            $table = $reference['tablename'];
            if ($table !== 'tt_content' || !$backendUser->check('tables_select', $table)) {
                continue;
            }
            $record = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordWSOL($table, (int)$reference['recuid']);
            if (!$record) {
                continue;
            }
            $page = \TYPO3\CMS\Backend\Utility\BackendUtility::readPageAccess((int)$record['pid'], $backendUser->getPagePermsClause(1));
            if (!$page) {
                continue;
            }
            $editUrl = fn(string $table, int $uid): string => (string)$this->uris->buildUriFromRoute('record_edit', ['edit' => [$table => [$uid => 'edit']], 'returnUrl' => $returnUrl]);
            $references[] = [
                'pageTitle' => \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordTitle('pages', $page, false),
                'pageUid' => (int)$page['uid'],
                'pageUrl' => $backendUser->check('tables_modify', 'pages') && $backendUser->doesUserHaveAccess($page, 2) ? $editUrl('pages', (int)$page['uid']) : '',
                'contentTitle' => \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordTitle($table, $record, false),
                'contentUid' => (int)$record['uid'],
                'contentUrl' => $backendUser->check('tables_modify', $table) && $backendUser->doesUserHaveAccess($page, 16) ? $editUrl($table, (int)$record['uid']) : '',
            ];
        }
        return $references;
    }
    private function publicProfile(array $profile): array
    {
        return array_intersect_key($profile, array_flip(Edition::pro() ? ['purpose', 'responsible', 'responsible_user', 'notes', 'revision', 'updated_at'] : ['purpose', 'revision', 'updated_at']));
    }
    public function editorUrl(string $identifier): string
    {
        return (string)$this->uris->buildUriFromRoute((new Typo3Version())->getMajorVersion() >= 14 ? 'form_editor' : 'web_FormFormbuilder.FormEditor_index', ['formPersistenceIdentifier' => $identifier]);
    }
    public function page(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->guard();
            $identifier = $request->getQueryParams()['form'] ?? '';
            if (!is_string($identifier)) {
                return new JsonResponse(['error' => 'input'], 400);
            }
            if ($identifier === '' || ($request->getQueryParams()['tab'] ?? '') === 'transfer') {
                Edition::requirePro();
            }
            $form = $identifier !== '' ? $this->access->find($identifier) : null;
            $module = $this->templates->create($request);
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)->addInlineLanguageLabelFile('EXT:form_manager_plus/Resources/Private/Language/locallang.xlf', 'fmp.');
            if ($identifier === '' && Edition::pro()) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)->loadJavaScriptModule('@scheffer/form-manager-plus-pro/import-wizard.js');
            }
            $module->setTitle('Form Manager Plus');
            $language = str_starts_with((string)$GLOBALS['LANG']->getLocale(), 'de') ? 'de' : 'en';
            $config = ['api' => (string)$this->uris->buildUriFromRoute('ajax_form_manager_plus_tools'),
                'extensionVersion' => \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getExtensionVersion('form_manager_plus'),
                'csrf' => $this->protection->createFromRequest($request)->generateToken('form-manager-plus', 'write'),
                'pro' => Edition::pro(), 'identifier' => $identifier, 'name' => $form['name'] ?? '', 'language' => $language,
                'databaseForm' => $form && ctype_digit($identifier) && (new Typo3Version())->getMajorVersion() >= 14,
                'tab' => in_array($request->getQueryParams()['tab'] ?? '', ['profile', 'transfer', 'categories'], true) ? $request->getQueryParams()['tab'] : ($form ? 'profile' : 'transfer'),
                'editor' => $form ? $this->editorUrl($identifier) : '',
                'back' => $form ? $this->editorUrl($identifier) : (string)$this->uris->buildUriFromRoute((new Typo3Version())->getMajorVersion() >= 14 ? 'form_manager' : 'web_FormFormbuilder')];
            $module->assignMultiple(['pro' => Edition::pro(), 'config' => json_encode($config, JSON_THROW_ON_ERROR), 'formName' => $form['name'] ?? '', 'backUrl' => $config['back']]);
            return $module->renderResponse('Workbench');
        } catch (\DomainException) {
            return new JsonResponse(['error' => 'access'], 403);
        }
    }
    public function api(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        try {
            $this->guard();
            $method = $request->getMethod();
            if (!in_array($method, ['GET', 'POST'], true)) {
                return new JsonResponse(['error' => 'method'], 405, $headers);
            }
            if ($method === 'POST') {
                $raw = (string)$request->getBody();
                if (strlen($raw) > 5 * 1024 * 1024) {
                    throw new \InvalidArgumentException('file_size');
                }
                $input = json_decode($raw, true, 48, JSON_THROW_ON_ERROR);
                if (!is_array($input) || !$this->protection->createFromRequest($request)->validateToken((string)($input['csrf'] ?? ''), 'form-manager-plus', 'write')) {
                    return new JsonResponse(['error' => 'csrf'], 403, $headers);
                }
            } else {
                $input = $request->getQueryParams();
            }
            $op = $input['op'] ?? '';
            if (!is_string($op)) {
                throw new \InvalidArgumentException('input');
            }
            if (Edition::proOperation($op)) {
                Edition::requirePro();
            }
            if (in_array($op, ['bulk', 'favorite', 'profile_save', 'view_save', 'view_delete', 'preview', 'import', 'categories_save', 'editor_categories_save', 'category_rename', 'category_appearance'], true) && $method !== 'POST') {
                return new JsonResponse(['error' => 'method'], 405, $headers);
            }
            $form = [];
            $identifier = is_string($input['identifier'] ?? null) ? $input['identifier'] : '';
            if (in_array($op, ['history', 'references', 'details', 'favorite', 'profile_save', 'export'], true)) {
                $form = $this->access->find($identifier, $op === 'profile_save');
            }
            if (in_array($op, ['categories', 'categories_save'], true)) {
                if (!ctype_digit($identifier) || (new Typo3Version())->getMajorVersion() < 14) {
                    throw new \DomainException('access');
                }
                $this->access->find($identifier, $op === 'categories_save');
            }
            switch ($op) {
                case 'history':
                    $before = filter_var($input['before'] ?? 0, FILTER_VALIDATE_INT);
                    if ($before === false || $before < 0) {
                        throw new \InvalidArgumentException('input');
                    }
                    $result = $this->data->history($identifier, $before);
                    break;
                case 'editor_categories':
                case 'editor_categories_save':
                    $form = $this->access->find($identifier, $op === 'editor_categories_save');
                    $choices = $this->data->categories();
                    $profile = $this->data->profile($identifier);
                    $database = ctype_digit($identifier) && (new Typo3Version())->getMajorVersion() >= 14;
                    if ($op === 'editor_categories_save' && (!is_array($input['selected'] ?? null) || !is_int($input['revision'] ?? null) || !is_string($input['version'] ?? null))) {
                        throw new \InvalidArgumentException('input');
                    }
                    if ($database) {
                        if ($op === 'editor_categories_save' && !$GLOBALS['BE_USER']->check('tables_select', 'sys_category')) {
                            throw new \DomainException('access');
                        }
                        if ($op === 'editor_categories_save') {
                            $profile = $this->data->saveCategories($identifier, $input['selected'], $input['revision']);
                        }
                        $selected = array_map('intval', json_decode($profile['categories_json'] ?? '[]', true) ?: []);
                        $assignment = ['selected' => array_values(array_intersect($selected, array_map('intval', array_column($choices, 'uid')))), 'version' => '', 'editable' => empty($form['readOnly']) && empty($form['invalid']) && $GLOBALS['BE_USER']->check('tables_modify', 'form_definition') && $GLOBALS['BE_USER']->check('tables_select', 'sys_category')];
                    } else {
                        if ($op === 'editor_categories_save' && $input['revision'] !== (int)$profile['revision']) {
                            throw new \DomainException('conflict');
                        }
                        $assignment = $op === 'editor_categories_save'
                            ? $this->categories->saveFileAssignment($form, $choices, $input['selected'], $input['version'])
                            : $this->categories->fileAssignment($form, $choices);
                    }
                    if ($op === 'editor_categories_save') {
                        $this->access->clearCatalog();
                    }
                    $result = $assignment + ['categories' => $choices, 'revision' => (int)$profile['revision']];
                    break;
                case 'references':
                    $result = ['references' => $this->references($identifier)];
                    break;
                case 'category_appearance':
                    if (!is_int($input['uid'] ?? null) || !is_string($input['icon'] ?? null) || !is_string($input['color'] ?? null)) {
                        throw new \InvalidArgumentException('input');
                    }
                    $result = ['category' => $this->categories->appearance($input['uid'], $input['icon'], $input['color'])];
                    break;
                case 'category_rename':
                    if (!is_int($input['uid'] ?? null) || !is_string($input['title'] ?? null) || !is_string($input['previous'] ?? null)) {
                        throw new \InvalidArgumentException('input');
                    }
                    $result = ['title' => $this->categories->rename($input['uid'], $input['title'], $input['previous'])];
                    break;
                case 'details':
                    $editable = true;
                    $reason = '';
                    try {
                        $this->access->find($identifier, true);
                    } catch (\DomainException $exception) {
                        $editable = false;
                        $reason = $exception->getMessage();
                    }
                    $result = ['profile' => $this->publicProfile($this->data->profile($identifier)), 'responsibleUsers' => Edition::pro() ? $this->data->responsibleUsers() : [], 'editable' => $editable, 'reason' => $reason, 'form' => ['name' => $form['name'], 'identifier' => $identifier]];
                    break;
                case 'categories':
                    $profile = $this->data->profile($identifier);
                    $returnUrl = (string)$this->uris->buildUriFromRoute('form_manager_plus_tools', ['form' => $identifier, 'tab' => 'categories']);
                    $editable = true;
                    try {
                        $this->access->find($identifier, true);
                    } catch (\DomainException) {
                        $editable = false;
                    }
                    $choices = $this->data->categories();
                    $selected = array_map('intval', json_decode($profile['categories_json'] ?? '[]', true) ?: []);
                    $result = ['categories' => $choices, 'selected' => array_values(array_intersect($selected, array_map('intval', array_column($choices, 'uid')))),
                        'revision' => (int)$profile['revision'], 'editable' => $editable, 'createTargets' => $editable ? $this->categories->creationTargets($returnUrl) : []];
                    break;
                case 'categories_save':
                    if (!is_array($input['selected'] ?? null) || !is_int($input['revision'] ?? null)) {
                        throw new \InvalidArgumentException('input');
                    }
                    $profile = $this->data->saveCategories($identifier, $input['selected'], $input['revision']);
                    $result = ['revision' => (int)$profile['revision']];
                    break;
                case 'favorite':
                    if (!is_bool($input['favorite'] ?? null)) {
                        throw new \InvalidArgumentException('input');
                    }
                    $this->data->favorite($identifier, $input['favorite']);
                    $result = ['favorite' => $input['favorite']];
                    break;
                case 'profile_save':
                    if (!Edition::pro()) {
                        $before = $this->data->profile($identifier);
                        $input = array_replace($input, ['responsible' => $before['responsible'], 'notes' => $before['notes'], 'responsible_user' => (int)$before['responsible_user']]);
                    }
                    $result = ['profile' => $this->publicProfile($this->data->saveProfile($identifier, $input))];
                    $this->data->edited($identifier);
                    break;
                case 'views': $result = ['views' => $this->data->views(), 'groups' => $this->data->teamGroups(), 'responsibleUsers' => $this->data->responsibleUsers()];
                    break;
                case 'view_save': $result = ['views' => $this->data->saveView($input)];
                    break;
                case 'view_delete':
                    if (!is_int($input['id'] ?? null) || $input['id'] < 1) {
                        throw new \InvalidArgumentException('input');
                    }
                    $result = ['views' => $this->data->deleteView($input['id'])];
                    break;
                default: return $this->pro->handle($op, $input, $headers);
            }
            return new JsonResponse($result, 200, $headers);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $known = ['input', 'bulk_size', 'file_size', 'invalid_json', 'package', 'ticket'];
            return new JsonResponse(['error' => in_array($exception->getMessage(), $known, true) ? $exception->getMessage() : 'input'], 400, $headers);
        } catch (\DomainException $exception) {
            $known = ['pro_required', 'workspace_readonly', 'access', 'readonly', 'conflict', 'ambiguous_identifier', 'no_identifier_match', 'view_exists', 'view_limit', 'target', 'expired', 'dependencies', 'invalid_form'];
            $code = in_array($exception->getMessage(), $known, true) ? $exception->getMessage() : 'access';
            return new JsonResponse(['error' => $code], in_array($code, ['pro_required', 'workspace_readonly', 'access', 'readonly'], true) ? 403 : 409, $headers);
        } catch (\Throwable $exception) {
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__)->error('Form workbench operation failed', ['exception' => $exception]);
            return new JsonResponse(['error' => 'operation_failed'], 500, $headers);
        }
    }
}
