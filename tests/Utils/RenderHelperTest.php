<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Tests\Utils;

use Exception;
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
        $renderHelper = new RenderHelper();

        $this->assertEquals('Hallo', $renderHelper->ucfirst('Hallo'));
        $this->assertEquals('Hallo', $renderHelper->ucfirst('hallo'));
    }

    public function testGetSimpleClassName(): void
    {
        $renderHelper = new RenderHelper();

        $this->assertEquals('RenderHelper', $renderHelper->getSimpleClassName(RenderHelper::class));
        $this->assertEquals('Exception', $renderHelper->getSimpleClassName(Exception::class));
    }
}
