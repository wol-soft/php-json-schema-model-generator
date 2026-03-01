<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue113Test extends AbstractIssueTestCase
{
    public function testIfThenWithoutElseGeneratesClass(): void
    {
        $className = $this->generateClassFromFile('ifThenWithoutElse.json');

        $object = new $className([]);
        $this->assertNull($object->getDeleteMissing());
        $this->assertNull($object->getAudiences());
    }

    public function testIfConditionFalseDoesNotRequireAudiences(): void
    {
        $className = $this->generateClassFromFile('ifThenWithoutElse.json');

        $object = new $className(['delete_missing' => false]);
        $this->assertFalse($object->getDeleteMissing());
        $this->assertNull($object->getAudiences());
    }

    public function testIfConditionTrueRequiresAudiences(): void
    {
        $className = $this->generateClassFromFile('ifThenWithoutElse.json');

        $this->expectException(ConditionalException::class);
        $this->expectExceptionMessage('Missing required value for audiences');

        new $className(['delete_missing' => true]);
    }

    public function testIfConditionTrueWithAudiencesProvided(): void
    {
        $className = $this->generateClassFromFile('ifThenWithoutElse.json');

        $object = new $className(['delete_missing' => true, 'audiences' => ['group-a', 'group-b']]);
        $this->assertTrue($object->getDeleteMissing());
        $this->assertSame(['group-a', 'group-b'], $object->getAudiences());
    }
}
