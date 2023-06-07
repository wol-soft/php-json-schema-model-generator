<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Tests\Issues\AbstractIssueTest;

class Issue72Test extends AbstractIssueTest
{
    /**
     * If a root OneOf composition uses branches which only provide examples (which should cause every input to pass)
     * ignore those branches as it's most likely an error in the schema. The generation process will emit a warning.
     */
    public function testExampleOneOfBranchIsSkipped(): void
    {
        $className = $this->generateClassFromFile('OneOfExample.json');

        $object = new $className(['label' => 'Hannes']);
        $this->assertSame('Hannes', $object->getLabel());
    }

    public function testNestedAllOf(): void
    {
        $this->markTestSkipped('Not functional yet');

        $className = $this->generateClassFromFile('NestedAllOf.json');

        $company = new $className([
            'CEO' => [
                'yearsInCompany' => 10,
                'name' => 'Hannes',
                'salary' => 10000,
                'assistance' => [
                    'yearsInCompany' => 4,
                    'name' => 'Dieter',
                    'salary' => 8000,
                ],
            ],
        ]);

        $this->assertSame(10, $company->getCEO()->getYearsInCompany());
        $this->assertSame('Hannes', $company->getCEO()->getName());
        $this->assertSame(10000, $company->getCEO()->getSalary());
    }
}
