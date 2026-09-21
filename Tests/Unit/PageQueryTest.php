<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchefferWebdesign\FormManagerPlus\Service\PageQuery;

final class PageQueryTest extends TestCase
{
    public function testStorageFilterUsesExactDirectoryAndKeepsAuthorizedDataset(): void
    {
        $forms = [
            ['persistenceIdentifier' => '1:/forms/a.form.yaml', 'name' => 'A', 'group' => ''],
            ['persistenceIdentifier' => '1:/forms-other/b.form.yaml', 'name' => 'B', 'group' => ''],
            ['persistenceIdentifier' => '14', 'name' => 'C', 'group' => ''],
        ];
        $result = PageQuery::select($forms, PageQuery::parse(['storage' => '1:/forms/']));
        self::assertSame(1, $result['recordsFiltered']);
        self::assertSame('A', $result['forms'][0]['name']);
        self::assertSame('database', PageQuery::storageKey('14'));
    }
    public function testOversizedPagesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PageQuery::parse(['length' => 251]);
    }
}
