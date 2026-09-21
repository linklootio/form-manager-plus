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
use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use SchefferWebdesign\FormManagerPlus\Service\{AjaxFeature, CategoryProvider, FormCatalog, PageQuery};
use TYPO3\CMS\Core\Utility\GeneralUtility;

function verify(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException($label);
    } echo 'PASS ' . $label . PHP_EOL;
}

$forms = [];
$marketing = ['uid' => 1, 'title' => 'Marketing', 'icon' => 'folder', 'color' => '', 'ink' => ''];
$support = ['uid' => 2, 'title' => 'Support', 'icon' => 'folder', 'color' => '', 'ink' => ''];
$multiCategoryForms = [
    ['name' => 'Both', 'persistenceIdentifier' => 'both', 'group' => 'Marketing / Support', 'categoryItems' => [$marketing, $support, $support]],
    ['name' => 'Support only', 'persistenceIdentifier' => 'support', 'group' => 'Support', 'categoryItems' => [$support]],
    ['name' => 'None', 'persistenceIdentifier' => 'none', 'group' => '', 'categoryItems' => []],
];
$facets = PageQuery::categoryFacets($multiCategoryForms);
verify(array_column($facets, 'key') === ['group:', 'id:1', 'id:2'] && array_column($facets, 'count') === [1, 1, 2], 'sidebar has real categories, deduplicated counts and no synthetic combination');
verify(PageQuery::select($multiCategoryForms, PageQuery::parse(['category' => 'id:1']))['recordsFiltered'] === 1, 'multi-category form is found in Marketing');
verify(PageQuery::select($multiCategoryForms, PageQuery::parse(['category' => 'id:2']))['recordsFiltered'] === 2, 'multi-category form is also found in Support without duplicate rows');
verify(PageQuery::select($multiCategoryForms, PageQuery::parse(['category' => 'group:Support']))['recordsFiltered'] === 2, 'previously saved single-category title filter includes multiple assignments');
verify(PageQuery::select($multiCategoryForms, PageQuery::parse(['category' => 'group:Marketing / Support']))['recordsFiltered'] === 1, 'previously saved combination filter remains usable');
$multiCategoryForms[0]['categoryItems'][0]['title'] = 'Renamed';
verify(PageQuery::select($multiCategoryForms, PageQuery::parse(['category' => 'id:1']))['recordsFiltered'] === 1, 'UID category filter survives a renamed category');
$style = \SchefferWebdesign\FormManagerPlus\Service\CategoryStyle::item(['uid' => 1, 'title' => 'Marketing', 'tx_fmp_icon' => 'calendar', 'tx_fmp_color' => '#2563EB']);
verify($style['icon'] === 'calendar' && $style['color'] === '#2563eb' && $style['ink'] === '#ffffff', 'category style normalizes colour and keeps readable icon contrast');
$unsafeStyle = \SchefferWebdesign\FormManagerPlus\Service\CategoryStyle::item(['uid' => 1, 'title' => '<script>', 'tx_fmp_icon' => '../../evil.svg', 'tx_fmp_color' => 'red;position:fixed']);
verify($unsafeStyle['icon'] === 'folder' && $unsafeStyle['color'] === '', 'untrusted category icons and CSS values fall back safely');
verify(\SchefferWebdesign\FormManagerPlus\Service\CategoryStyle::item(['uid' => 1, 'title' => 'Light', 'tx_fmp_color' => '#ffffff'])['ink'] === '#000000', 'light category colours use a dark icon');
$libraryFixtures = [
    ['name' => 'Zeta', 'group' => 'A', 'purpose' => 'Contact', 'modifiedAt' => 20, 'persistenceIdentifier' => 'z'],
    ['name' => 'Alpha', 'group' => 'Z', 'purpose' => 'Bookings', 'modifiedAt' => 30, 'persistenceIdentifier' => 'a'],
];
$library = PageQuery::select($libraryFixtures, PageQuery::parse(['grouped' => '0']));
verify($library['forms'][0]['name'] === 'Alpha', 'library name order is global, not silently grouped');
$groupedLibrary = PageQuery::select($libraryFixtures, PageQuery::parse(['grouped' => '1']));
verify($groupedLibrary['forms'][0]['name'] === 'Zeta', 'optional category grouping remains available');
verify(PageQuery::select($libraryFixtures, PageQuery::parse(['grouped' => '0', 'sort' => 'modifiedAt', 'direction' => 'desc']))['forms'][0]['name'] === 'Alpha', 'modified date order is global');
verify(PageQuery::select($libraryFixtures, PageQuery::parse(['grouped' => '0', 'sort' => 'purpose']))['forms'][0]['name'] === 'Alpha', 'purpose column sorts before pagination');
verify(PageQuery::parse(['grouped' => false])['grouped'] === false, 'named views preserve ungrouped state');
for ($i = 0; $i < 5000; $i++) {
    $forms[] = ['fileUid' => $i + 1, 'name' => 'Form ' . $i, 'persistenceIdentifier' => '1:/demo/form-' . $i . '.form.yaml', 'referenceCount' => $i % 12, 'group' => $i % 2 ? 'Marketing' : 'Support'];
}
$first = PageQuery::select($forms, PageQuery::parse(['start' => 0, 'length' => 10]));
$next = PageQuery::select($forms, PageQuery::parse(['start' => 10, 'length' => 10]));
verify(count($first['forms']) === 10 && $first['recordsTotal'] === 5000, '5000 forms return only 10 requested rows');
verify(!array_intersect(array_column($first['forms'], 'fileUid'), array_column($next['forms'], 'fileUid')), 'adjacent pages do not duplicate rows');
$filtered = PageQuery::select($forms, PageQuery::parse(['category' => 'group:Marketing', 'search' => 'Form 1', 'length' => 25]));
verify(count($filtered['forms']) <= 25 && $filtered['recordsFiltered'] < 2500, 'category and search are applied before paging');
foreach ($filtered['forms'] as $form) {
    verify($form['group'] === 'Marketing' && str_contains($form['name'], 'Form 1'), 'returned row matches both filters');
}
verify(PageQuery::select($forms, PageQuery::parse(['search' => '<script>alert(1)</script>']))['recordsFiltered'] === 0, 'markup-looking search is literal');
foreach ([['length' => -1], ['length' => 251], ['start' => -5], ['search' => ['bad']]] as $input) {
    try {
        PageQuery::parse($input);
        throw new RuntimeException('Unsafe query accepted');
    } catch (InvalidArgumentException) {
        echo "PASS invalid query rejected\n";
    }
}
$clamped = PageQuery::select($forms, PageQuery::parse(['start' => 9000, 'length' => 25]));
verify($clamped['start'] === 4975 && count($clamped['forms']) === 25, 'stale saved page is bounded to the last available page');
$timing = microtime(true);
for ($i = 0; $i < 10; $i++) {
    PageQuery::select($forms, PageQuery::parse(['start' => $i * 25, 'length' => 25]));
}
echo '5000-row metadata selection, ten pages: ' . round((microtime(true) - $timing) * 1000) . "ms\n";

$source = new class implements FormSourceInterface {
    public int $calls = 0;
    public function listForms(): array
    {
        $this->calls++;
        return [['name' => 'Cache fixture', 'persistenceIdentifier' => 'EXT:fixture/form.yaml', 'fileUid' => 0, 'referenceCount' => 0]];
    }
};
$container = GeneralUtility::getContainer();
$pool = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
$catalog = new FormCatalog(
    $source,
    new CategoryProvider($pool),
    GeneralUtility::makeInstance(\TYPO3\CMS\Form\Service\DatabaseService::class),
    GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
);
$catalog->clear();
$catalog->forms(true);
for ($i = 0; $i < 10; $i++) {
    $catalog->forms();
}
verify($source->calls === 1, 'ten warm pages reuse catalogue without scanning form sources');
$catalog->clear();
$catalog->forms();
verify($source->calls === 2, 'invalidation forces a new catalogue');
$originalUid = $GLOBALS['BE_USER']->user['uid'];
$GLOBALS['BE_USER']->user['uid'] = (int)$originalUid + 100000;
$catalog->forms();
verify($source->calls === 3, 'another backend identity cannot reuse the catalogue');
$GLOBALS['BE_USER']->user['uid'] = $originalUid;
$originalPermissions = $GLOBALS['BE_USER']->groupData['tables_modify'] ?? '';
$GLOBALS['BE_USER']->groupData['tables_modify'] = $originalPermissions . ',fmp_test_permission';
$catalog->forms();
verify($source->calls === 4, 'permission changes invalidate the cache key');
$GLOBALS['BE_USER']->groupData['tables_modify'] = $originalPermissions;
$catalog->clear();
$major = (new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion();
$class = $major >= 14
    ? \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager14::class
    : \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager13::class;
$shell = (new ReflectionClass($class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($shell, 'getAvailableFormDefinitions');
$arguments = $major >= 14 ? [[], new \TYPO3\CMS\Form\Domain\DTO\SearchCriteria()] : [[]];
verify($method->invokeArgs($shell, $arguments) === [], 'initial Core controller defers the complete form list');
unset($GLOBALS['BE_USER']);
verify(!AjaxFeature::allowed(), 'anonymous caller cannot access the AJAX list');
