<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * An update (setter, populate) must reject data the constructor rejects, also if the update violates an object-level
 * keyword of the schema (maxProperties, propertyNames, patternProperties, additionalProperties) or the schema
 * dependency of another property.
 */
class SetterObjectLevelValidationTest extends AbstractPHPModelGeneratorTestCase
{
    public static function objectLevelKeywordProvider(): array
    {
        $keywords = [
            'maxProperties' => ', "maxProperties": 1',
            'propertyNames' => ', "propertyNames": {"pattern": "^kind$"}',
            'patternProperties' => ', "patternProperties": {"^note$": {"type": "string", "minLength": 3}}',
            'schema dependency of another property' => ', "dependencies": {"kind": {"maxProperties": 1}}',
        ];

        $cases = [];
        foreach ($keywords as $name => $keyword) {
            $cases[$name] = [$keyword, false];
            $cases["$name (collect errors)"] = [$keyword, true];
        }

        return $cases;
    }

    #[DataProvider('objectLevelKeywordProvider')]
    public function testSetterViolatingObjectLevelKeywordIsRejectedLikeTheConstructor(
        string $keyword,
        bool $collectErrors,
    ): void {
        $this->addPopulatePostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'RootObjectLevelKeyword.json',
            [$keyword],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
            false,
        );

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, 'setNote', 'note');

        // an update which keeps the data valid is accepted
        $object = new $className(['kind' => 'k']);
        $object->setKind('l');
        $this->assertSame(['kind' => 'l'], $object->meta()->rawInput());
    }

    /**
     * A property declared only by a composition branch isn't declared by the schema itself: the additionalProperties
     * constraint of the schema applies to it.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testSetterOfPropertyNotDeclaredByTheSchemaIsRejectedLikeTheConstructor(bool $collectErrors): void
    {
        $this->addPopulatePostProcessor();

        $className = $this->generateClassFromFile(
            'BranchPropertyNotDeclaredByRoot.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
        );

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, 'setNote', 'note');
    }

    public static function collectErrorsProvider(): array
    {
        return [
            'direct exception' => [false],
            'collect errors' => [true],
        ];
    }

    public static function noObjectLevelValidationProvider(): array
    {
        return [
            'additionalProperties false' => ['false'],
            'additional properties stored' => ['true'],
            'additional properties with schema' => ['{"type": "string"}'],
        ];
    }

    /**
     * The setter of a declared property can't violate the additionalProperties constraint of the schema: the setter
     * neither validates the object-level validators nor snapshots the model state, and the base validation keeps its
     * single parameter.
     */
    #[DataProvider('noObjectLevelValidationProvider')]
    public function testSetterOfDeclaredPropertyDoesNotValidateAdditionalProperties(string $additionalProperties): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));
        };

        $className = $this->generateClassFromFileTemplate(
            'NoObjectLevelValidationRequired.json',
            [$additionalProperties],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $object = new $className(['kind' => 'k']);
        $object->setKind('l');
        $this->assertSame(['kind' => 'l'], $object->meta()->rawInput());

        $reflection = new ReflectionClass($className);
        $source = file_get_contents($reflection->getFileName());

        preg_match('/function setKind\(.*?\n    }\n/s', $source, $setKind);
        $this->assertStringNotContainsString('_executeObjectValidators', $setKind[0]);
        $this->assertStringNotContainsString('_backupModelState', $setKind[0]);

        $this->assertCount(1, $reflection->getMethod('_executeBaseValidators')->getParameters());
    }

    /**
     * Updates the property of a model with kind 'k' via the setter and via populate: each update must be rejected like
     * the constructor rejects the resulting data, and leave the model unchanged.
     */
    private function assertUpdatesAreRejectedLikeTheConstructor(
        string $className,
        string $setter,
        string $property,
    ): void {
        $expectedException = $this->catchException(
            static fn() => new $className(['kind' => 'k', $property => 'n']),
        );
        $this->assertNotNull($expectedException, 'The constructor must reject the data');

        $updates = [
            'setter' => static fn(object $object) => $object->$setter('n'),
            'populate' => static fn(object $object) => $object->populate([$property => 'n']),
        ];

        foreach ($updates as $update => $executeUpdate) {
            $object = new $className(['kind' => 'k']);
            $actualException = $this->catchException(static fn() => $executeUpdate($object));

            $this->assertNotNull($actualException, "The $update must be rejected");
            $this->assertSame($expectedException::class, $actualException::class, $update);
            $this->assertSame($expectedException->getMessage(), $actualException->getMessage(), $update);
            $this->assertSame(['kind' => 'k'], $object->meta()->rawInput(), $update);
            $this->assertNull($object->{'get' . ucfirst($property)}(), $update);
        }
    }

    private function addPopulatePostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };
    }
}
