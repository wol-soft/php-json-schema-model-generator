<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue110Test extends AbstractIssueTestCase
{
    public function testEnumInComposition(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new BuilderClassPostProcessor());
        };
        $classNameBuilder = $this->generateClassFromFile(
            'anythingGoesObject.json',
            (new GeneratorConfiguration())->setSerialization(true),
        ) . 'Builder';

        $data = [
            'ext' => [
                'result link to look at' => 'http://example.com'
            ],
        ];

        $object = new $classNameBuilder();
        $object->setMediaBuyId('123');
        $object->setExt(['xxxx' => 'yyyy']);
        $result = $object->validate()->toArray();
        self::assertNotNull($result['ext']); // <--- my problem is that ext gets reset.
        // I think its because both "oneOf" definitions have ext set and in the validation you set it to null
        // if a validation fails. So the first one finds a match, yay ext is not set to null. The second one fails and sets ext to null
        // now you conclude the validation with ext = null. Which is incorrect. I will paste the code in the issue for you to look at.
        self::assertArrayNotHasKey('errors', $result); // this is not a pressing issue? just found it weird that errors is returned as well even though never set
    }
}
