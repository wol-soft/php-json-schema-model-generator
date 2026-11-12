<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An object-level `not` composition must be evaluated again by an update which changes a key the branch refers to:
 * a cached "branch didn't match" result must not outlive the update which makes the branch match. The reference for
 * each update is the constructor, which must throw the same exception for the resulting model data.
 */
class ComposedNotUpdateTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * [schema file, stored data, rejected update (property, value), accepted update (property, value)]
     */
    public static function notVariantProvider(): array
    {
        $variants = [
            'branch with required only' => ['NotRequiredOnly.json', ['a' => 'x'], ['b', 'y'], ['a', 'z']],
            'branch declaring the property' => ['NotDeclaringProperty.json', ['a' => 'x'], ['b', 'y'], ['a', 'z']],
            'branch with explicit object type' => ['NotTypedObject.json', ['a' => 'x'], ['b', 'y'], ['a', 'z']],
            'branch requiring two properties' => ['NotRequiredBoth.json', ['a' => 'x'], ['b', 'y'], ['a', 'z']],
            'branch with const value of a required property' => ['NotConstValue.json', [], ['b', 'x'], ['b', 'y']],
            'conditional then branch with not' => ['ConditionalNot.json', ['a' => 'x'], ['b', 'y'], ['a', 'z']],
        ];

        $cases = [];
        foreach ($variants as $name => $variant) {
            $cases[$name] = [...$variant, false];
            $cases["$name (collect errors)"] = [...$variant, true];
        }

        return $cases;
    }

    #[DataProvider('notVariantProvider')]
    public function testUpdateMatchingTheNotBranchIsRejectedLikeTheConstructor(
        string $schemaFile,
        array $storedData,
        array $rejectedUpdate,
        array $acceptedUpdate,
        bool $collectErrors,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
        );

        [$rejectedProperty, $rejectedValue] = $rejectedUpdate;

        $expectedException = $this->catchException(
            static fn() => new $className($storedData + [$rejectedProperty => $rejectedValue]),
        );
        $this->assertNotNull($expectedException, 'The constructor must reject the data');

        $updates = [
            'setter' => static fn(object $object) => $object->{'set' . ucfirst($rejectedProperty)}($rejectedValue),
            'populate' => static fn(object $object) => $object->populate([$rejectedProperty => $rejectedValue]),
        ];

        foreach ($updates as $update => $executeUpdate) {
            $object = new $className($storedData);
            $actualException = $this->catchException(static fn() => $executeUpdate($object));

            $this->assertNotNull($actualException, "The $update must be rejected");
            $this->assertSame($expectedException::class, $actualException::class, $update);
            $this->assertSame($expectedException->getMessage(), $actualException->getMessage(), $update);
            $this->assertSame($storedData, $object->meta()->rawInput(), $update);
            $this->assertNull($object->{'get' . ucfirst($rejectedProperty)}(), $update);

            // the rejected update must not leave a cached composition result behind: an update which keeps the
            // data valid is accepted afterwards
            [$acceptedProperty, $acceptedValue] = $acceptedUpdate;
            $object->{'set' . ucfirst($acceptedProperty)}($acceptedValue);
            $this->assertSame($acceptedValue, $object->{'get' . ucfirst($acceptedProperty)}(), $update);
        }
    }

    /**
     * Without a stored property the not branch doesn't match: the first update which adds the property the branch
     * requires must be rejected, an update of another property must not be.
     */
    public function testSetterAddingTheRequiredPropertyToAnEmptyModelIsRejected(): void
    {
        $className = $this->generateClassFromFile(
            'NotRequiredOnly.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className([]);
        $object->setA('x');
        $this->assertSame('x', $object->getA());

        $exception = $this->catchException(static fn() => $object->setB('y'));
        $this->assertNotNull($exception, 'The setter must be rejected');
        $this->assertSame(['a' => 'x'], $object->meta()->rawInput());
        $this->assertNull($object->getB());
    }
}
