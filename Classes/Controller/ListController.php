<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SchefferWebdesign\FormManagerPlus\Service\{AjaxFeature, CategoryActions, FormCatalog, FormSignals, PageQuery, RowPresenter, WorkspaceData};
use TYPO3\CMS\Core\Http\JsonResponse;

final class ListController
{
    public function __construct(private FormCatalog $catalog, private RowPresenter $presenter, private FormSignals $signals, private WorkspaceData $workspaceData, private CategoryActions $categoryActions) {}
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->getMethod() !== 'GET') {
            return new JsonResponse(['error' => 'Method not allowed'], 405, $headers + ['Allow' => 'GET']);
        }
        if (!AjaxFeature::allowed() || !AjaxFeature::enabled()) {
            return new JsonResponse(['error' => 'Access denied'], 403, $headers);
        }
        try {
            $query = PageQuery::parse($request->getQueryParams());
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid table query'], 400, $headers);
        }
        try {
            return $this->renderList($query, $headers);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException|\Doctrine\DBAL\Exception\InvalidFieldNameException $exception) {
            \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(self::class)->error('Form catalogue database schema is incomplete. Run extension:setup.', ['exception' => $exception]);
            return new JsonResponse(['error' => 'schema_setup_required'], 503, $headers);
        }
    }
    private function renderList(array $query, array $headers): ResponseInterface
    {
        // Ownership filters apply only to the already authorized form catalogue.
        // A shared view may reference an owner outside the viewer's user picker.
        if (!\SchefferWebdesign\FormManagerPlus\Service\Edition::pro()) {
            $query['responsible'] = $query['team'] = '';
            if (in_array($query['mode'], ['mine', 'unreferenced', 'stale'], true)) {
                $query['mode'] = '';
            }
        }
        $forms = $this->workspaceData->enrich($this->signals->enrich($this->catalog->forms($query['refresh'])));
        if (!\SchefferWebdesign\FormManagerPlus\Service\Edition::pro()) {
            foreach ($forms as &$item) {
                $item['responsible'] = '';
                $item['responsibleUser'] = 0;
                $item['mine'] = false;
            }
        }
        unset($item);
        $result = PageQuery::select($forms, $query);
        $result['storageFacets'] = PageQuery::storageFacets($forms);
        $result['summary'] = ['all' => count($forms)];
        $result['categoryCounts'] = array_count_values(array_column($forms, 'group'));
        $styles = $editUrls = [];
        foreach ($forms as $form) {
            foreach ($form['categoryItems'] ?? [] as $item) {
                $editUrls[$item['uid']] ??= \SchefferWebdesign\FormManagerPlus\Service\Edition::pro() ? $this->categoryActions->appearanceUrl($item['uid']) : '';
                $styles[$form['group']][$item['uid']] = $item + ['editUrl' => $editUrls[$item['uid']]];
            }
        }
        $result['categoryStyles'] = array_map('array_values', $styles);
        $result['categoryFacets'] = array_map(fn($item) => $item + ['editUrl' => $editUrls[$item['uid']] ?? '', 'canRename' => $item['uid'] > 0 && $this->categoryActions->canRename($item['uid'])], PageQuery::categoryFacets($forms));
        foreach (['mine', 'favorites', 'recent', 'unreferenced', 'stale', 'invalid'] as $mode) {
            $result['summary'][$mode] = count(array_filter($forms, static fn($form) => PageQuery::matchesMode($form, $mode)));
        }
        $result['data'] = array_values(array_filter(array_map($this->presenter->present(...), $result['forms'])));
        unset($result['forms']);
        return new JsonResponse($result, 200, $headers + ['X-FMP-Catalog' => $this->catalog->wasCacheHit() ? 'hit' : 'rebuilt']);
    }
}
