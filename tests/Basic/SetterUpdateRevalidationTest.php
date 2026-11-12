<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A setter and populate() must reject an update if the resulting state would be rejected by the constructor, also if
 * the violated validator is attached to another property or to the schema itself: a schema dependency of a property
 * which re-declares the written property, and the keyword evaluated against the set of keys (maxProperties,
 * propertyNames).
 */
class SetterUpdateRevalidationTest extends AbstractPHPModelGeneratorTestCase
{
    public static function collectErrorsProvider(): array
    {
        return [
            'direct exception' => [false],
            'collect errors' => [true],
        ];
    }

    #[DataProvider('collectErrorsProvider')]
    public function testUpdateOfPropertyRedeclaredBySchemaDependencyIsRejectedLikeTheConstructor(
        bool $collectErrors,
    ): void {
        $className = $this->generateMutableClass('SchemaDependencyRedeclaringProperty.json', $collectErrors);

        $this->assertUpdatesAreRejectedLikeTheConstructor(
            $className,
            ['a' => 'x', 'b' => 'abc'],
            'b',
            'ab',
        );

        // a stored dependency trigger doesn't reject an update of the trigger itself
        $object = new $className(['a' => 'x', 'b' => 'abc']);
        $object->setA('y');
        $this->assertSame(['a' => 'y', 'b' => 'abc'], $object->meta()->rawInput());

        // without the trigger the dependency doesn't apply to the written property
        $object = new $className(['b' => 'abc']);
        $object->setB('ab');
        $this->assertSame('ab', $object->getB());

        $object = new $className(['b' => 'abc']);
        $object->populate(['b' => 'ab']);
        $this->assertSame('ab', $object->getB());

        // a valid value of the dependency schema is accepted
        $object = new $className(['a' => 'x', 'b' => 'abc']);
        $object->setB('abcd');
        $this->assertSame('abcd', $object->getB());
    }

    #[DataProvider('collectErrorsProvider')]
    public function testSetterOfNestedObjectRejectsUpdateViolatingSchemaDependencyLikeTheConstructor(
        bool $collectErrors,
    ): void {
        $className = $this->generateMutableClass('NestedSchemaDependencyRedeclaringProperty.json', $collectErrors);

        $object = new $className(['inner' => ['a' => 'x', 'b' => 'abc']]);
        $innerClassName = $object->getInner()::class;

        $expectedException = $this->catchException(
            static fn() => new $innerClassName(['a' => 'x', 'b' => 'ab']),
        );
        $this->assertNotNull($expectedException, 'The constructor of the nested class must reject the data');

        $actualException = $this->catchException(static fn() => $object->getInner()->setB('ab'));

        $this->assertNotNull($actualException, 'The setter of the nested object must be rejected');
        $this->assertSame($expectedException::class, $actualException::class);
        $this->assertSame($expectedException->getMessage(), $actualException->getMessage());
        $this->assertSame('abc', $object->getInner()->getB());

        // setting the whole nested object constructs the nested class and is rejected as well
        $this->assertNotNull($this->catchException(
            static fn() => $object->setInner(['a' => 'x', 'b' => 'ab']),
        ));
    }

    #[DataProvider('collectErrorsProvider')]
    public function testUpdateAddingAKeyBeyondMaxPropertiesIsRejectedLikeTheConstructor(bool $collectErrors): void
    {
        $className = $this->generateMutableClass('MaxPropertiesTwoOfThree.json', $collectErrors);

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, ['a' => 'x', 'b' => 'y'], 'c', 'z');

        // replacing the value of a stored key doesn't add a key
        $object = new $className(['a' => 'x', 'b' => 'y']);
        $object->setB('q');
        $this->assertSame(['a' => 'x', 'b' => 'q'], $object->meta()->rawInput());

        $object->populate(['a' => 'r']);
        $this->assertSame(['a' => 'r', 'b' => 'q'], $object->meta()->rawInput());

        // a model below the limit accepts the key
        $object = new $className(['a' => 'x']);
        $object->setC('z');
        $this->assertSame(['a' => 'x', 'c' => 'z'], $object->meta()->rawInput());
    }

    #[DataProvider('collectErrorsProvider')]
    public function testUpdateAddingAPropertyViolatingPropertyNamesIsRejectedLikeTheConstructor(
        bool $collectErrors,
    ): void {
        $className = $this->generateMutableClass('PropertyNamesViolatedByDeclaredProperty.json', $collectErrors);

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, ['a' => 'x'], 'zzz', 'x');

        // a property with a valid name is accepted
        $object = new $className(['a' => 'x']);
        $object->setA('y');
        $this->assertSame(['a' => 'y'], $object->meta()->rawInput());
    }

    /**
     * Updates the property of a model via the setter and via populate(): each update must be rejected like the
     * constructor rejects the resulting data, and leave the model unchanged.
     */
    private function assertUpdatesAreRejectedLikeTheConstructor(
        string $className,
        array $storedData,
        string $property,
        string $value,
    ): void {
        $expectedException = $this->catchException(
            static fn() => new $className([$property => $value] + $storedData),
        );
        $this->assertNotNull($expectedException, 'The constructor must reject the data');

        $getter = 'get' . ucfirst($property);
        $storedValue = (new $className($storedData))->$getter();

        $updates = [
            'setter' => static fn(object $object) => $object->{'set' . ucfirst($property)}($value),
            'populate' => static fn(object $object) => $object->populate([$property => $value]),
        ];

        foreach ($updates as $update => $executeUpdate) {
            $object = new $className($storedData);
            $actualException = $this->catchException(static fn() => $executeUpdate($object));

            $this->assertNotNull($actualException, "The $update must be rejected");
            $this->assertSame($expectedException::class, $actualException::class, $update);
            $this->assertSame($expectedException->getMessage(), $actualException->getMessage(), $update);
            $this->assertSame($storedData, $object->meta()->rawInput(), $update);
            $this->assertSame($storedValue, $object->$getter(), $update);
        }
    }

    private function generateMutableClass(string $schemaFile, bool $collectErrors): string
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        return $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
        );
    }
}
