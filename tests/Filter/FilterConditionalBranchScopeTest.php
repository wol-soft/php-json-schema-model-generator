<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;

/**
 * A filter declared on a property inside an if/then/else branch must only run while that branch is
 * active. The branch property is transferred to the generated root class; the transferred copy
 * must not carry the branch's filter unconditionally.
 */
#[ApplicableDrafts]
class FilterConditionalBranchScopeTest extends AbstractFilterTestCase
{
    public function testFilterInConditionalBranchOnlyRunsWhileBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchFilters.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // if matches: the then branch is active, the else branch is inactive.
        $object = new $className(['kind' => 'a', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('then', $object->getThenValue());
        $this->assertSame('  else  ', $object->getElseValue());

        // if does not match: the else branch is active, the then branch is inactive.
        $object = new $className(['kind' => 'b', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  then  ', $object->getThenValue());
        $this->assertSame('else', $object->getElseValue());

        // The if property is absent: "required" makes the if fail, so the else branch is active.
        $object = new $className(['thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  then  ', $object->getThenValue());
        $this->assertSame('else', $object->getElseValue());

        // The setters re-evaluate the conditional: the inactive branch's filter must stay silent.
        $object = new $className(['kind' => 'a']);
        $object->setElseValue('  else  ');
        $this->assertSame('  else  ', $object->getElseValue());
        $object->setThenValue('  then  ');
        $this->assertSame('then', $object->getThenValue());
    }
}
