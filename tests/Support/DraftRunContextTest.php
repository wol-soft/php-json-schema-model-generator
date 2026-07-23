<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use PHPUnit\Framework\TestCase;

final class DraftRunContextTest extends TestCase
{
    protected function setUp(): void
    {
        DraftRunContext::reset();
    }

    protected function tearDown(): void
    {
        DraftRunContext::reset();
    }

    public function testGetDraftForDataNameReturnsNullForUnregisteredKey(): void
    {
        $this->assertNull(DraftRunContext::getDraftForDataName('SomeClass', 'someMethod', 'someDataName'));
    }

    public function testGetDraftForDataNameReturnsRegisteredDraft(): void
    {
        $draft = JsonSchemaDraft::DRAFT_07;
        DraftRunContext::registerDraftForDataName('SomeClass', 'someMethod', 'Draft 07', $draft);

        $this->assertSame($draft, DraftRunContext::getDraftForDataName('SomeClass', 'someMethod', 'Draft 07'));
    }

    public function testGetDraftForDataNameReturnsNullForDifferentDataName(): void
    {
        DraftRunContext::registerDraftForDataName('SomeClass', 'someMethod', 'Draft 07', JsonSchemaDraft::DRAFT_07);

        $this->assertNull(DraftRunContext::getDraftForDataName('SomeClass', 'someMethod', 'Draft 2019-09'));
    }

    public function testGetDraftForDataNameReturnsNullForDifferentMethod(): void
    {
        DraftRunContext::registerDraftForDataName('SomeClass', 'methodA', 'Draft 07', JsonSchemaDraft::DRAFT_07);

        $this->assertNull(DraftRunContext::getDraftForDataName('SomeClass', 'methodB', 'Draft 07'));
    }

    public function testGetDraftForDataNameReturnsNullForDifferentClass(): void
    {
        DraftRunContext::registerDraftForDataName('ClassA', 'someMethod', 'Draft 07', JsonSchemaDraft::DRAFT_07);

        $this->assertNull(DraftRunContext::getDraftForDataName('ClassB', 'someMethod', 'Draft 07'));
    }

    public function testMultipleEntriesAreStoredIndependently(): void
    {
        DraftRunContext::registerDraftForDataName('ClassA', 'method1', 'Draft 07', JsonSchemaDraft::DRAFT_07);
        DraftRunContext::registerDraftForDataName('ClassA', 'method1', 'Draft 2019-09', JsonSchemaDraft::DRAFT_2019_09);
        DraftRunContext::registerDraftForDataName('ClassB', 'method1', 'Draft 07', JsonSchemaDraft::DRAFT_07);

        $this->assertSame(
            JsonSchemaDraft::DRAFT_07,
            DraftRunContext::getDraftForDataName('ClassA', 'method1', 'Draft 07'),
        );
        $this->assertSame(
            JsonSchemaDraft::DRAFT_2019_09,
            DraftRunContext::getDraftForDataName('ClassA', 'method1', 'Draft 2019-09'),
        );
        $this->assertSame(
            JsonSchemaDraft::DRAFT_07,
            DraftRunContext::getDraftForDataName('ClassB', 'method1', 'Draft 07'),
        );
    }

    public function testResetClearsAllRegisteredDrafts(): void
    {
        DraftRunContext::registerDraftForDataName('ClassA', 'method1', 'Draft 07', JsonSchemaDraft::DRAFT_07);
        DraftRunContext::registerDraftForDataName('ClassB', 'method2', 'Draft 2019-09', JsonSchemaDraft::DRAFT_2019_09);

        DraftRunContext::reset();

        $this->assertNull(DraftRunContext::getDraftForDataName('ClassA', 'method1', 'Draft 07'));
        $this->assertNull(DraftRunContext::getDraftForDataName('ClassB', 'method2', 'Draft 2019-09'));
    }

    public function testRegisterOverwritesPreviousEntryForSameKey(): void
    {
        DraftRunContext::registerDraftForDataName('SomeClass', 'someMethod', 'Draft 07', JsonSchemaDraft::DRAFT_07);
        DraftRunContext::registerDraftForDataName('SomeClass', 'someMethod', 'Draft 07', JsonSchemaDraft::DRAFT_2020_12);

        $this->assertSame(
            JsonSchemaDraft::DRAFT_2020_12,
            DraftRunContext::getDraftForDataName('SomeClass', 'someMethod', 'Draft 07'),
        );
    }
}
