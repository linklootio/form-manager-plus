<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Tests\Functional;

use SchefferWebdesign\FormManagerPlus\Service\{Edition, FormLifecycle, WorkspaceData};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EditionAndLifecycleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['form'];
    protected function setUp(): void
    {
        $base = dirname((new \ReflectionClass(Edition::class))->getFileName(), 3);
        $this->testExtensionsToLoad = [$base];

        parent::setUp();
    }

    public function testClassicModePackageMetadataIsAcceptedByCore(): void
    {
        $environment = [
            \TYPO3\CMS\Core\Core\Environment::getContext(),
            \TYPO3\CMS\Core\Core\Environment::isCli(),
            \TYPO3\CMS\Core\Core\Environment::isComposerMode(),
            \TYPO3\CMS\Core\Core\Environment::getProjectPath(),
            \TYPO3\CMS\Core\Core\Environment::getPublicPath(),
            \TYPO3\CMS\Core\Core\Environment::getVarPath(),
            \TYPO3\CMS\Core\Core\Environment::getConfigPath(),
            \TYPO3\CMS\Core\Core\Environment::getCurrentScript(),
            \TYPO3\CMS\Core\Core\Environment::toArray()['os'],
        ];
        try {
            $classic = $environment;
            $classic[2] = false;
            \TYPO3\CMS\Core\Core\Environment::initialize(...$classic);
            $manager = new \TYPO3\CMS\Core\Package\PackageManager(new \TYPO3\CMS\Core\Service\DependencyOrderingService());
            $package = new \TYPO3\CMS\Core\Package\Package($manager, 'form_manager_plus', dirname(__DIR__, 2) . '/');
            self::assertSame('1.0.0', $package->getPackageMetaData()->getVersion());
            self::assertSame('form_manager_plus', $package->getPackageKey());
        } finally {
            \TYPO3\CMS\Core\Core\Environment::initialize(...$environment);
        }
    }
    public function testEditionAndDependencyInjection(): void
    {
        self::assertFalse(Edition::pro());
        $provider = GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class);
        self::assertInstanceOf(\SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class, $provider);
        if (!Edition::pro()) {
            $this->expectException(\DomainException::class);
            Edition::requirePro();
        } else {
            self::assertInstanceOf(\SchefferWebdesign\FormManagerPlusPro\Service\FormTransfer::class, GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlusPro\Service\FormTransfer::class));
        }
    }

    public function testProApiCannotBeEnabledWithRequestParameters(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'qa', 'admin' => 1, 'password' => 'disabled-test-account']);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en');
        $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'] = ($GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'] ?? '') . "\ntemplates.typo3/cms-form.1700 = scheffer-webdesign/form-manager-plus:Resources/Private\n";
        $request = (new \TYPO3\CMS\Core\Http\ServerRequest('https://example.test/typo3/'))
            ->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams(['op' => 'bulk', 'pro' => '1']);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = GeneralUtility::getContainer()->get(\SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class);
        $response = $controller->api($request);
        self::assertSame(Edition::pro() ? 405 : 403, $response->getStatusCode());
        self::assertSame(Edition::pro() ? 'method' : 'pro_required', json_decode((string)$response->getBody(), true)['error']);
    }
    public function testFalEventsOnlyRelocateAfterSuccessfulRename(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $old = '1:/forms/old.form.yaml';
        $current = $old;
        $pool->getConnectionForTable('tx_formmanagerplus_profile')->insert('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key($old), 'form_identifier' => $old, 'purpose' => 'Event metadata']);
        $file = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $file->method('getIdentifier')->willReturnCallback(static function () use (&$current): string {
            return substr($current, 2);
        });
        $file->method('getCombinedIdentifier')->willReturnCallback(static function () use (&$current): string {
            return $current;
        });
        $listener = new \SchefferWebdesign\FormManagerPlus\EventListener\FormFileLifecycle(GeneralUtility::getContainer()->get(FormLifecycle::class));
        $listener->before(new \TYPO3\CMS\Core\Resource\Event\BeforeFileRenamedEvent($file, 'new.form.yaml'));
        $data = new WorkspaceData($pool);
        self::assertSame('Event metadata', $data->profile($old)['purpose']);
        $current = '1:/forms/new.form.yaml';
        $listener->after(new \TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent($file, 'new.form.yaml'));
        self::assertSame('Event metadata', $data->profile($current)['purpose']);
        self::assertSame('', $data->profile($old)['purpose']);
        $listener->before(new \TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent($file));
        $listener->after(new \TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent($file));
        self::assertSame('', $data->profile($current)['purpose']);
    }

    public function testNestedFormsFollowRenamedFolder(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $old = '1:/forms/nested/test.form.yaml';
        $pool->getConnectionForTable('tx_formmanagerplus_profile')->insert('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key($old), 'form_identifier' => $old, 'purpose' => 'Nested form']);
        $file = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $file->method('getIdentifier')->willReturn('/forms/nested/test.form.yaml');
        $file->method('getCombinedIdentifier')->willReturn($old);
        $folder = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);
        $folder->method('getCombinedIdentifier')->willReturn('1:/forms/');
        $folder->method('getFiles')->willReturn([$file]);
        $target = $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class);
        $target->method('getCombinedIdentifier')->willReturn('1:/archive/');
        $listener = new \SchefferWebdesign\FormManagerPlus\EventListener\FormFileLifecycle(GeneralUtility::getContainer()->get(FormLifecycle::class));
        $listener->beforeFolder(new \TYPO3\CMS\Core\Resource\Event\BeforeFolderRenamedEvent($folder, 'archive'));
        $listener->afterFolder(new \TYPO3\CMS\Core\Resource\Event\AfterFolderRenamedEvent($target, $folder));
        self::assertSame('Nested form', (new WorkspaceData($pool))->profile('1:/archive/nested/test.form.yaml')['purpose']);
    }

    public function testNonLiveWorkspaceCannotChangeSharedProfiles(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'qa', 'admin' => 1, 'password' => 'disabled-test-account']);
        $user = $this->setUpBackendUser(1);
        $user->workspace = 42;
        $data = new WorkspaceData($pool);
        $identifier = '1:/workspace.form.yaml';
        $data->assignCreator($identifier);
        self::assertSame(0, $data->profile($identifier)['responsible_user']);
        foreach ([static fn() => $data->saveProfile($identifier, ['purpose' => 'Must not persist', 'responsible' => '', 'notes' => '', 'revision' => 0]), static fn() => $data->saveCategories($identifier, [], 0)] as $write) {
            try {
                $write();
                self::fail('Workspace write was accepted');
            } catch (\DomainException $exception) {
                self::assertSame('workspace_readonly', $exception->getMessage());
            }
        }
        self::assertSame('', $data->profile($identifier)['purpose']);
        $user->workspace = 0;
    }

    public function testConcurrentProfileChangeIsRejectedWithoutLosingSavedValue(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'qa', 'admin' => 1, 'password' => 'disabled-test-account']);
        $this->setUpBackendUser(1);
        $data = new WorkspaceData($pool);
        $identifier = '1:/concurrent.form.yaml';
        $input = ['purpose' => 'First edit', 'responsible' => '', 'notes' => '', 'revision' => 0];
        $data->saveProfile($identifier, $input);
        try {
            $data->saveProfile($identifier, array_replace($input, ['purpose' => 'Stale edit']));
            self::fail('Stale revision accepted');
        } catch (\DomainException $exception) {
            self::assertSame('conflict', $exception->getMessage());
        }
        self::assertSame('First edit', $data->profile($identifier)['purpose']);
    }

    public function testDatabaseFormMetadataSurvivesSoftDeleteAndRestore(): void
    {
        if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('Database form storage is a TYPO3 14 feature.');
        }
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $forms = $pool->getConnectionForTable('form_definition');
        $forms->insert('form_definition', ['uid' => 500, 'deleted' => 1]);
        $pool->getConnectionForTable('tx_formmanagerplus_profile')->insert('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key('500'), 'form_identifier' => '500', 'purpose' => 'Restore this too']);
        $lifecycle = GeneralUtility::getContainer()->get(FormLifecycle::class);
        $data = new WorkspaceData($pool);
        $lifecycle->deleted('500');
        self::assertSame('Restore this too', $data->profile('500')['purpose']);
        $forms->update('form_definition', ['deleted' => 0], ['uid' => 500]);
        self::assertSame('Restore this too', $data->profile('500')['purpose']);
        $forms->delete('form_definition', ['uid' => 500]);
        $lifecycle->deleted('500');
        self::assertSame('', $data->profile('500')['purpose']);
    }
    public function testRelocationAndDeletionDoNotReuseStaleMetadata(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $old = '1:/forms/old.form.yaml';
        $new = '1:/archive/new.form.yaml';
        $profiles = $pool->getConnectionForTable('tx_formmanagerplus_profile');
        $profiles->insert('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key($old), 'form_identifier' => $old, 'purpose' => 'Keep source']);
        $profiles->insert('tx_formmanagerplus_profile', ['form_key' => WorkspaceData::key($new), 'form_identifier' => $new, 'purpose' => 'Stale destination']);
        $personal = $pool->getConnectionForTable('tx_formmanagerplus_personal');
        $personal->insert('tx_formmanagerplus_personal', ['be_user' => 42, 'workspace' => 0, 'form_key' => WorkspaceData::key($old), 'favorite' => 1]);
        $lifecycle = GeneralUtility::getContainer()->get(FormLifecycle::class);
        $lifecycle->relocate($old, $new);
        $data = new WorkspaceData($pool);
        self::assertSame('Keep source', $data->profile($new)['purpose']);
        self::assertSame('', $data->profile($old)['purpose']);
        self::assertSame(1, (int)$personal->count('*', 'tx_formmanagerplus_personal', ['form_key' => WorkspaceData::key($new)]));
        $lifecycle->forget($new);
        self::assertSame('', $data->profile($new)['purpose']);
        self::assertSame(0, (int)$personal->count('*', 'tx_formmanagerplus_personal', ['form_key' => WorkspaceData::key($new)]));
    }
}
