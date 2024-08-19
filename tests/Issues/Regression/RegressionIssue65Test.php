<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Regression;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class RegressionIssue65Test extends AbstractIssueTestCase
{
    public function testValidInput(): void
    {
        $className = $this->generateClassFromFile('regression.json');

        $object = new $className(['list' => [['id' => 10, 'name' => 'Hans']], 'label' => 'visitors']);

        $this->assertSame('visitors', $object->getLabel());
        $this->assertCount(1, $object->getList());
        $this->assertSame(10, $object->getList()[0]->getId());
        $this->assertSame('Hans', $object->getList()[0]->getName());
    }

    /**
     * @dataProvider invalidInputDataProvider
     */
    public function testInvalidInput(array $input): void
    {
        $this->expectException(AllOfException::class);

        $className = $this->generateClassFromFile('regression.json');

        new $className($input);
    }

    public function invalidInputDataProvider(): array
    {
        return [
            'invalid label' => [['label' => 10]],
            'invalid list element' => [['list' => [['id' => 10, 'name' => 'Hans'], 10]]],
            'invalid id in list element' => [['list' => [['id' => '10', 'name' => 'Hans']]]],
            'invalid name in list element' => [['list' => [['id' => 10, 'name' => false]]]],
        ];
    }
}
