<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use PHPUnit\Framework\TestCase;

final class ApplicableDraftsTest extends TestCase
{
    public function testDefaultRangeIncludesAllDrafts(): void
    {
        $attribute = new ApplicableDrafts();

        $this->assertSame(JsonSchemaDraft::cases(), $attribute->draftsInRange());
    }

    public function testFromDraft201909IncludesTwoLatestDrafts(): void
    {
        $attribute = new ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09);

        $this->assertSame(
            [JsonSchemaDraft::DRAFT_2019_09, JsonSchemaDraft::DRAFT_2020_12],
            $attribute->draftsInRange(),
        );
    }

    public function testFromDraft202012IncludesOnlyLatestDraft(): void
    {
        $attribute = new ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2020_12);

        $this->assertSame([JsonSchemaDraft::DRAFT_2020_12], $attribute->draftsInRange());
    }

    public function testUntilDraft201909ExcludesLatestDraft(): void
    {
        $attribute = new ApplicableDrafts(until: JsonSchemaDraft::DRAFT_2019_09);

        $this->assertSame(
            [JsonSchemaDraft::DRAFT_07, JsonSchemaDraft::DRAFT_2019_09],
            $attribute->draftsInRange(),
        );
    }

    public function testSingleDraftRangeReturnsThatDraftOnly(): void
    {
        $attribute = new ApplicableDrafts(
            from: JsonSchemaDraft::DRAFT_2019_09,
            until: JsonSchemaDraft::DRAFT_2019_09,
        );

        $this->assertSame([JsonSchemaDraft::DRAFT_2019_09], $attribute->draftsInRange());
    }

    public function testLatestApplicableReturnsLastCaseForDefaultRange(): void
    {
        $attribute = new ApplicableDrafts();

        $this->assertSame(JsonSchemaDraft::DRAFT_2020_12, $attribute->latestApplicable());
    }

    public function testLatestApplicableReturnsLastCaseForPartialRange(): void
    {
        $attribute = new ApplicableDrafts(until: JsonSchemaDraft::DRAFT_2019_09);

        $this->assertSame(JsonSchemaDraft::DRAFT_2019_09, $attribute->latestApplicable());
    }

    public function testLatestApplicableReturnsSingleDraftWhenRangeIsExact(): void
    {
        $attribute = new ApplicableDrafts(
            from: JsonSchemaDraft::DRAFT_07,
            until: JsonSchemaDraft::DRAFT_07,
        );

        $this->assertSame(JsonSchemaDraft::DRAFT_07, $attribute->latestApplicable());
    }

    public function testDraftsInRangeResultsAreIndexedFromZero(): void
    {
        $attribute = new ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09);
        $drafts    = $attribute->draftsInRange();

        $this->assertArrayHasKey(0, $drafts);
        $this->assertArrayHasKey(1, $drafts);
        $this->assertArrayNotHasKey(2, $drafts);
    }
}
