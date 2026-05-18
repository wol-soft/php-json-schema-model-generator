<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Draft_2019_09;
use PHPModelGenerator\Draft\Draft_2020_12;
use PHPModelGenerator\Draft\DraftInterface;

enum JsonSchemaDraft: int
{
    case DRAFT_07      = 700;
    case DRAFT_2019_09 = 201909;
    case DRAFT_2020_12 = 202012;

    public function createDraftInstance(): DraftInterface
    {
        return match ($this) {
            self::DRAFT_07      => new Draft_07(),
            self::DRAFT_2019_09 => new Draft_2019_09(),
            self::DRAFT_2020_12 => new Draft_2020_12(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT_07      => 'Draft 07',
            self::DRAFT_2019_09 => 'Draft 2019-09',
            self::DRAFT_2020_12 => 'Draft 2020-12',
        };
    }
}
