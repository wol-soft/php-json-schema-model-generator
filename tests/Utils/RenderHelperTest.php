<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Tests\Utils;

use Exception;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Utils\RenderHelper;
use PHPUnit\Framework\TestCase;

/**
 * Class RenderHelperTest
 *
 * @package PHPModelGenerator\Tests\Utils
 */
class RenderHelperTest extends TestCase
{
    public function testUcfirst(): void
    {
        $renderHelper = new RenderHelper(new GeneratorConfiguration());

        $this->assertSame('Hallo', $renderHelper->ucfirst('Hallo'));
        $this->assertSame('Hallo', $renderHelper->ucfirst('hallo'));
    }

    public function testGetSimpleClassName(): void
    {
        $renderHelper = new RenderHelper(new GeneratorConfiguration());

        $this->assertSame('RenderHelper', $renderHelper->getSimpleClassName(RenderHelper::class));
        $this->assertSame('Exception', $renderHelper->getSimpleClassName(Exception::class));
    }
}
