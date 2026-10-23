<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\ModelUpdate;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\ObjectUpdateDependency;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;

/**
 * Validates the dependencies of the properties outside the validation of the property: an update of another property
 * (setter, populate) or the removal of a key may change the result of a dependency.
 */
class DependencyValidation
{
    public function __construct(private readonly GeneratorConfiguration $generatorConfiguration)
    {}

    /**
     * Adds _executeDependencyValidators() to the schema on first use
     */
    public function addMethod(Schema $schema): void
    {
        if ($schema->hasMethod('_executeDependencyValidators')) {
            return;
        }

        $schema->addMethod(
            '_executeDependencyValidators',
            new RenderedMethod(
                $schema,
                $this->generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Dependencies', 'ExecuteDependencyValidators.phptpl']),
                ['dependencyValidators' => $schema->getDependencyValidators()],
            ),
        );
    }

    /**
     * Whether populate() must validate the dependencies of the properties it doesn't update (adds the method
     * executing them if so). populate() may update any key: each property and any undeclared key are checked.
     */
    public function addForPopulate(Schema $schema): bool
    {
        foreach ($schema->getDependencyValidators() as $validators) {
            $dependency = ObjectUpdateDependency::ofValidators($validators, null);

            foreach ($schema->getProperties() as $property) {
                if (!$property->isInternal()) {
                    $dependency = $dependency->strongest(
                        ObjectUpdateDependency::ofValidators($validators, $property->getName()),
                    );
                }
            }

            if ($dependency->requiresValidation(true)) {
                $this->addMethod($schema);

                return true;
            }
        }

        return false;
    }
}
