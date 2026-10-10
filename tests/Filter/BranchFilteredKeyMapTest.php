<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use ArrayObject;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;

/**
 * The map of filtered properties which the active branch of a composition hands over to the schema
 * containing the composition.
 */
#[ApplicableDrafts]
class BranchFilteredKeyMapTest extends AbstractFilterTestCase
{
    /**
     * @return AbstractComposedPropertyValidator[]
     */
    private function generateAndCaptureComposedValidators(string $schemaFile): array
    {
        $capturedValidators = new ArrayObject();

        $capturingPostProcessor = new class ($capturedValidators) extends PostProcessor {
            public function __construct(private readonly ArrayObject $capturedValidators)
            {
            }

            public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
            {
                foreach ($schema->getBaseValidators() as $validator) {
                    if ($validator instanceof AbstractComposedPropertyValidator) {
                        $this->capturedValidators->append($validator);
                    }
                }
            }
        };

        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($capturingPostProcessor): void {
            $generator->addPostProcessor($capturingPostProcessor);
        };

        $this->generateClassFromFile($schemaFile);

        return $capturedValidators->getArrayCopy();
    }

    public function testAnyOfKeepsAFilterWhichEveryBranchDeclaresOnTheSchema(): void
    {
        $validators = $this->generateAndCaptureComposedValidators('AnyOfBranchesSameFilter.json');

        $this->assertCount(1, $validators);
        $this->assertSame([], $validators[0]->getBranchFilteredKeyMap());
    }

    public function testAllOfKeepsAPropertyWhichIsFilteredDirectlyInABranchOnTheSchema(): void
    {
        $validators = $this->generateAndCaptureComposedValidators('AllOfBranch.json');

        $this->assertCount(1, $validators);
        $this->assertSame([], $validators[0]->getBranchFilteredKeyMap());
    }
}
