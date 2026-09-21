<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;
use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use SchefferWebdesign\FormManagerPlus\Service\CategoryProvider;
use SchefferWebdesign\FormManagerPlus\Service\FormList;
use SchefferWebdesign\FormManagerPlus\ViewHelpers\FormsViewHelper;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Form\Service\DatabaseService;
use TYPO3Fluid\Fluid\View\TemplateView;

// Run against an isolated TYPO3 vendor directory; no application bootstrap or DB.
$vendor = getenv('FMP_VENDOR') ?: dirname(__DIR__, 2) . '/vendor';
$loader = require $vendor . '/autoload.php';
$loader->addPsr4('SchefferWebdesign\\FormManagerPlus\\', dirname(__DIR__, 2) . '/Classes');
if ((new Typo3Version())->getMajorVersion() >= 14) {
    putenv('TYPO3_PATH_ROOT=' . dirname($vendor) . '/public');
    SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
    Bootstrap::init($loader);
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
$GLOBALS['TYPO3_CONF_VARS']['DB']['additionalQueryRestrictions'] = [];
$GLOBALS['TCA'] = [
    'sys_file_metadata' => ['ctrl' => ['delete' => 'deleted']],
    'sys_category' => ['ctrl' => ['delete' => 'deleted']],
];
if ((new Typo3Version())->getMajorVersion() >= 14) {
    GeneralUtility::makeInstance(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);
}
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => Connection::class]);
$connection->executeStatement('CREATE TABLE sys_file_metadata (uid INTEGER, file INTEGER, sys_language_uid INTEGER, deleted INTEGER DEFAULT 0)');
$connection->executeStatement("CREATE TABLE sys_category (uid INTEGER, title TEXT, deleted INTEGER DEFAULT 0, tx_fmp_icon TEXT DEFAULT 'folder', tx_fmp_color TEXT DEFAULT '')");
$connection->executeStatement('CREATE TABLE sys_category_record_mm (uid_local INTEGER, uid_foreign INTEGER, tablenames TEXT, fieldname TEXT)');
$connection->executeStatement('INSERT INTO sys_file_metadata VALUES (1, 10, 0, 0), (2, 20, 0, 0), (3, 30, 0, 0), (4, 40, 0, 1)');
$connection->executeStatement("INSERT INTO sys_category (uid,title,deleted) VALUES (1, 'Support', 0), (2, 'Marketing', 0), (3, 'Deleted', 1)");
$connection->executeStatement("UPDATE sys_category SET tx_fmp_icon='envelope',tx_fmp_color='#2563eb' WHERE uid=1");
$connection->executeStatement("INSERT INTO sys_category_record_mm VALUES (1, 1, 'sys_file_metadata', 'categories'), (2, 1, 'sys_file_metadata', 'categories'), (1, 2, 'sys_file_metadata', 'categories'), (3, 3, 'sys_file_metadata', 'categories'), (1, 4, 'sys_file_metadata', 'categories'), (1, 3, 'tt_content', 'categories')");
$pool = new class ($connection) extends ConnectionPool {
    public function __construct(private Connection $connection) {}
    public function getQueryBuilderForTable(string $tableName): QueryBuilder
    {
        return $this->connection->createQueryBuilder();
    }
};
$categories = new CategoryProvider($pool);
check($categories->forFiles([]) === [], 'empty input stays empty');
check($categories->forFiles([10]) === [10 => 'Marketing / Support'], 'multiple categories are stable and do not duplicate forms');
check($categories->forFiles([30, 40]) === [], 'deleted categories, deleted metadata and foreign relations are excluded');
check(!isset($categories->forFiles([10])[20]), 'unlisted files are never included');
$categoryItems = $categories->itemsForFiles([10])[10];
check(count($categoryItems) === 2 && $categoryItems[1]['uid'] === 1 && $categoryItems[1]['icon'] === 'envelope' && $categoryItems[1]['color'] === '#2563eb', 'multiple category identities retain their own icon and colour');

$source = new class implements FormSourceInterface {
    public function listForms(): array
    {
        return [
            ['name' => 'Editable', 'fileUid' => 10, 'persistenceIdentifier' => '1:/forms/contact.form.yaml', 'invalid' => false, 'readOnly' => false, 'removable' => true, 'referenceCount' => 7],
            ['name' => '<script>alert(1)</script>', 'fileUid' => 20, 'persistenceIdentifier' => '1:/forms/read.form.yaml', 'invalid' => false, 'readOnly' => true, 'removable' => false],
            ['name' => 'Invalid', 'persistenceIdentifier' => 'EXT:demo/broken.form.yaml', 'invalid' => true, 'readOnly' => false, 'removable' => false],
            ['name' => 'Database form', 'persistenceIdentifier' => '123', 'invalid' => false, 'readOnly' => false, 'removable' => true, 'referenceCount' => 3],
            ['name' => 'Unused', 'fileUid' => 50, 'persistenceIdentifier' => '1:/forms/unused.form.yaml', 'invalid' => false, 'readOnly' => false, 'removable' => true, 'referenceCount' => 0],
        ];
    }
};
$references = new class extends DatabaseService {
    public function __construct() {}
    public function getAllReferencesForFileUid(): array
    {
        return [10 => 5];
    }
    public function getAllReferencesForPersistenceIdentifier(): array
    {
        return ['EXT:demo/broken.form.yaml' => 2];
    }
};
$uris = new class extends UriBuilder {
    public function __construct() {}
    public function buildUriFromRoute($name, $parameters = [], $referenceType = self::ABSOLUTE_PATH)
    {
        return new Uri('/' . $name . '?' . http_build_query($parameters));
    }
};
$forms = (new FormList($source, $categories, $references, $uris))->getForms();
$major = (new Typo3Version())->getMajorVersion();
check(count($forms) === 5, 'all authorized rows remain present');
check($forms[0]['group'] === 'Marketing / Support', 'FAL categories decorate file forms');
check($forms[1]['editUrl'] === '' && $forms[2]['editUrl'] === '', 'read-only and invalid forms have no edit links');
check($forms[2]['fileUid'] === 0 && $forms[2]['group'] === '', 'extension forms work without FAL metadata');
check($forms[0]['referenceCount'] === ($major < 14 ? 5 : 7), 'Core reference counts remain intact');
if ($major >= 14) {
    check(str_starts_with($forms[0]['editUrl'], '/form_editor?'), 'TYPO3 14 uses the separate editor route');
    check(str_contains($forms[3]['historyUrl'], 'form_definition%3A123'), 'database forms keep record history');
    check($forms[3]['referenceCount'] === 3, 'database form reference counts are preserved');
} else {
    check(str_contains($forms[0]['editUrl'], 'web_FormFormbuilder'), 'TYPO3 11–13 use the original editor module');
    check($forms[2]['referenceCount'] === 2, 'extension path references are preserved');
}
echo 'TYPO3 ' . (new Typo3Version())->getVersion() . ' functional smoke checks passed.' . PHP_EOL;

if ($major >= 14) {
    $container = GeneralUtility::getContainer();
    $resolvedHelper = $container->get(FormsViewHelper::class);
    check((new ReflectionProperty($resolvedHelper, 'formList'))->isInitialized($resolvedHelper), 'DI injects the form list into the ViewHelper');
    $GLOBALS['LANG'] = $container->get(LanguageServiceFactory::class)->create('en');
    $helper = $resolvedHelper;
    $helper->injectFormList(new FormList($source, $categories, $references, $uris));
    GeneralUtility::addInstance(FormsViewHelper::class, $helper);
    $renderingContext = $container->get(RenderingContextFactory::class)->create([
        'partialRootPaths' => [dirname(__DIR__, 2) . '/Resources/Private/Partials'],
    ], (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE));
    $testContainer = new class ($container, $helper) implements ContainerInterface {
        public function __construct(private ContainerInterface $inner, private FormsViewHelper $helper) {}
        public function has(string $id): bool
        {
            return $id === FormsViewHelper::class || $this->inner->has($id);
        }
        public function get(string $id): mixed
        {
            return $id === FormsViewHelper::class ? $this->helper : $this->inner->get($id);
        }
    };
    $renderingContext->setViewHelperResolver(new ViewHelperResolver(
        $testContainer,
        $renderingContext->getViewHelperResolver()->getNamespaces(),
    ));
    $renderingContext->getTemplatePaths()->setTemplateSource('<f:render partial="FormManagerPlus/LegacyList" />');
    $view = new TemplateView($renderingContext);
    $html = $view->render();
    check(substr_count($html, 'data-form data-uid=') === 5, 'Fluid renders the complete list');
    check(substr_count($html, 'data-identifier="removeForm"') === 1, 'Fluid hides removal for referenced, invalid and read-only fixtures');
    check(substr_count($html, 'data-identifier="duplicateForm"') === 4, 'Fluid excludes invalid forms from duplication');
    check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'), 'stored titles are escaped in rendered HTML');
    check(str_contains($html, 'Form management'), 'extension translations resolve');
    check(!str_contains($html, 'fmp-category-chip') && !str_contains($html, 'fmp-create-category') && !str_contains($html, 'fmp-category-intro'), 'category assignment and creation are absent from the list');
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $actionLinks = $xpath->query('//td[contains(@class,"fmp-actions")]/a');
    check($actionLinks->length > 0, 'list action links are present');
    foreach ($actionLinks as $link) {
        check($link->getAttribute('aria-label') !== '' && $link->getAttribute('title') !== '' && trim($link->textContent) === '', 'list action has an accessible name and icon-only presentation');
    }
    $renderingContext->getTemplatePaths()->setTemplateSource('<f:render partial="FormManagerPlus/AjaxList" arguments="{tableConfig: tableConfig}" />');
    $ajaxView = new TemplateView($renderingContext);
    $ajaxView->assign('tableConfig', ['url' => '/ajax/test', 'stateKey' => 'test', 'major' => 14, 'language' => 'en']);
    $shellHtml = $ajaxView->render();
    check(str_contains($shellHtml, 'data-fmp-ajax') && !str_contains($shellHtml, 'data-form data-uid=') && !str_contains($shellHtml, 'Unused'), 'AJAX shell does not render the full form data');
    check(substr_count($shellHtml, 'data-identifier=') === 3, 'native action proxies are present before Core binds its handlers');
}
