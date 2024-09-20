<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue70Test extends AbstractIssueTestCase
{
    /**
     * @dataProvider validInputDataProvider
     */
    public function testValidInput(string $filter, array $input, string|int|null $expectedOutput): void
    {
        $className = $this->generateClassFromFileTemplate(
            'filterInCompositionInArray.json',
            [$filter],
            (new GeneratorConfiguration())->addFilter($this->getFilter()),
        );

        $object = new $className($input);

        $this->assertCount(1, $object->getItems());
        $this->assertSame('Hello', $object->getItems()[0]->getTitle());
        $this->assertSame($expectedOutput, $object->getItems()[0]->getProperty());
    }

    public function validInputDataProvider(): array
    {
        return [
            'basic filter - default value' => ['trim', ['items' => [['title' => ' Hello ']]], 'now'],
            'basic filter - custom value - not modified' => ['trim', ['items' => [['title' => ' Hello ', 'property' => 'later']]], 'later'],
            'basic filter - custom value - modified' => ['trim', ['items' => [['title' => ' Hello ', 'property' => '   later   ']]], 'later'],
            'basic filter - null' => ['trim', ['items' => [['title' => ' Hello ', 'property' => null]]], null],
            'transforming filter - default value' => ['countChars', ['items' => [['title' => ' Hello ']]], 3],
            'transforming filter - transformed value' => ['countChars', ['items' => [['title' => ' Hello ', 'property' => 5]]], 5],
            'transforming filter - custom value' => ['countChars', ['items' => [['title' => ' Hello ', 'property' => 'Hello World']]], 11],
            'transforming filter - null' => ['countChars', ['items' => [['title' => ' Hello ', 'property' => null]]], null],
        ];
    }

    public function getFilter(): TransformingFilterInterface
    {
        return new class () implements TransformingFilterInterface {
            public function getAcceptedTypes(): array
            {
                return ['string', 'null'];
            }

            public function getToken(): string
            {
                return 'countChars';
            }

            public function getFilter(): array
            {
                return [Issue70Test::class, 'filter'];
            }

            public function getSerializer(): array
            {
                return [Issue70Test::class, 'filter'];
            }
        };
    }

    public static function filter(?string $input): ?int
    {
        return $input === null ? null : strlen($input);
    }
}
