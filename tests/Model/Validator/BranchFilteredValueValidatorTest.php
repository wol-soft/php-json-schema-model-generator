<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\BranchFilteredValueValidator;
use PHPUnit\Framework\TestCase;

class BranchFilteredValueValidatorTest extends TestCase
{
    public function testValidatorIsNoCheckAndSubstitutesTheStagedValueBeforeTheCommittedValue(): void
    {
        $validator = new BranchFilteredValueValidator(
            new Property('name', new PropertyType('string'), new JsonSchema('', ['type' => 'string'])),
        );

        $this->assertSame('', $validator->getCheck());

        $this->assertSame(
            "if (array_key_exists('name', \$this->_stagedBranchFilteredValues)) {\n"
                . "    if (\$this->_stagedBranchFilteredValues['name'] !== null) {\n"
                . "        \$value = \$this->_stagedBranchFilteredValues['name'][0];\n"
                . "    }\n"
                . "} elseif (array_key_exists('name', \$this->_branchFilteredValues)) {\n"
                . "    \$value = \$this->_branchFilteredValues['name'];\n"
                . '}',
            $validator->getStatement(),
        );
    }
}
