<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

#[ApplicableDrafts]
class IdenticalNestedSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testIdenticalSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalSubSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment(),
        );

        $object = new $className([
            'object1' => ['property1' => 'Hello'],
            'object2' => ['property1' => 'Goodbye'],
        ]);

        $this->assertSame('Hello', $object->getObject1()->getProperty1());
        $this->assertSame('Goodbye', $object->getObject2()->getProperty1());

        $this->assertSame($object->getObject1()::class, $object->getObject2()::class);
    }

    public function testIdenticalReferencedSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalReferencedSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment(),
        );

        $object = new $className([
            'object1' => ['member' => ['name' => 'Hannes', 'age' => 42]],
            'object2' => ['member' => ['name' => 'Frida', 'age' => 24]],
        ]);

        $this->assertSame('Hannes', $object->getObject1()->getMember()->getName());
        $this->assertSame(42, $object->getObject1()->getMember()->getAge());

        $this->assertSame('Frida', $object->getObject2()->getMember()->getName());
        $this->assertSame(24, $object->getObject2()->getMember()->getAge());

        $this->assertSame($object->getObject1()->getMember()::class, $object->getObject2()->getMember()::class);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('identicalReferencedSchemaDataProvider')]
    public function testIdenticalReferencedSchemaInMultipleFilesAreMappedToOneClass(
        string $file,
        string $class1Suffix,
        string $class2Suffix,
    ): void {
        $this->generateDirectory($file, (new GeneratorConfiguration())->setNamespacePrefix($file));

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $object1 = new ("\\{$namespacePrefix}\\{$class1Suffix}")(['member' => ['name' => 'Hannes', 'age' => 42]]);
        $this->assertSame('Hannes', $object1->getMember()->getName());
        $this->assertSame(42, $object1->getMember()->getAge());

        $object2 = new ("\\{$namespacePrefix}\\{$class2Suffix}")(['member' => ['name' => 'Frida', 'age' => 24]]);
        $this->assertSame('Frida', $object2->getMember()->getName());
        $this->assertSame(24, $object2->getMember()->getAge());

        $this->assertSame($object1->getMember()::class, $object2->getMember()::class);
    }

    public static function identicalReferencedSchemaDataProvider(): array
    {
        return [
            'In same namespace' => [
                'IdenticalReferencedSchemaInSameNamespace',
                'Schema1',
                'Schema2',
            ],
            'In different namespace' => [
                'IdenticalReferencedSchemaInDifferentNamespace',
                'SubFolder1\\Schema',
                'SubFolder2\\Schema',
            ],
        ];
    }

    public function testIdenticalSchemasInDifferentNamespaceAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaDifferentNamespace',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaDifferentNamespace'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\DifferentNamespaceSubFolder10\\SubSchema")(
            ['object1' => ['property1' => 'Hello']],
        );
        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\DifferentNamespaceSubFolder20\\SubSchema")(
            ['object1' => ['property1' => 'Goodbye']],
        );
        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()::class);
    }

    public function testIdenticalSchemasInArraysAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaInArray'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\ArraySubFolder1\\SubSchema")(
            ['object1' => [['property1' => 'Hello']]],
        );
        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\ArraySubFolder2\\SubSchema")(
            ['object1' => [['property1' => 'Goodbye']]],
        );
        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());

        $this->assertSame($subObject1->getObject1()[0]::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasInCompositionAreMappedToOneClass(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateDirectory(
            'IdenticalSubSchemaInComposition',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('IdenticalSubSchemaInComposition')
                ->setLogger($recordingLogger),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $entries = $recordingLogger->getEntries();

        $this->assertTrue(
            $this->hasLogEntry($entries, 'info', 'Generated class {class}', [
                'class' => "{$namespacePrefix}\\CompositionSubFolder1\\SubSchema",
            ]),
            'Expected a "Generated class" log entry for CompositionSubFolder1\SubSchema.',
        );
        $this->assertTrue(
            $this->hasLogEntry($entries, 'info', 'Rendered class {class}', [
                'class' => "{$namespacePrefix}\\CompositionSubFolder1\\SubSchema",
            ]),
            'Expected a "Rendered class" log entry for CompositionSubFolder1\SubSchema.',
        );
        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'notice',
                'Duplicated signature {signature} for class {class}. Redirecting to {redirectClass}',
            ),
            'Expected a duplicated-signature log entry for the identical nested schemas.',
        );
        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Empty composition for '{property}' may lead to unexpected results",
                ['property' => 'property2'],
            ),
            'Expected an empty-composition warning for property2.',
        );

        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(
            ['object1' => ['property1' => 'Hello'], 'property3' => 3],
        );

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(
            ['object1' => ['property1' => 'Goodbye'], 'property3' => true],
        );

        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()::class);
    }

    public function testIdenticalSchemasInCompositionInArrayAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInCompositionInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchema'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(
            ['object1' => [['property1' => 'Hello']], 'property3' => 3],
        );

        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(
            ['object1' => [['property1' => 'Goodbye']], 'property3' => true],
        );

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()[0]::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasCombined1AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined1',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined1'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(
            ['object1' => ['property1' => 'Hello'], 'property3' => 3],
        );

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(
            ['object1' => [['property1' => 'Goodbye']], 'property3' => true],
        );

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasCombined2AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined2',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined2'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(
            ['object1' => ['property1' => 'Hello']],
        );

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")([
            'object1' => [['property1' => 'Goodbye']],
            'property3' => true,
            'extendedProperty' => 'Wow so many compositions',
        ]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());
        $this->assertSame('Wow so many compositions', $subObject2->getExtendedProperty());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()[0]::class);
    }

    /**
     * Nested schemas which differ only in the order of a list whose order matters (the items of a tuple) are
     * different classes; a different order of a list which is a set (required) still maps to one class.
     */
    public function testNestedSchemasDifferingOnlyInTheOrderOfTupleItemsAreDifferentClasses(): void
    {
        $className = $this->generateClassFromFile(
            'NestedSchemasDifferingInListOrder.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([
            'first' => ['pair' => ['a', 1], 'label' => 'x'],
            'second' => ['pair' => [1, 'a'], 'label' => 'y'],
            'third' => ['pair' => ['b', 2], 'label' => 'z'],
        ]);

        $this->assertSame([1, 'a'], $object->getSecond()->getPair());
        $this->assertNotSame($object->getFirst()::class, $object->getSecond()::class);
        $this->assertSame($object->getFirst()::class, $object->getThird()::class);
    }

    /**
     * Nested object schemas which differ only in `unevaluatedProperties` are different classes. The keyword was not
     * among the fields the signature is built from, so whichever schema was generated first lent its class to the
     * other: a strict schema silently lost the keyword (accepting an invalid instance), a loose one silently gained it
     * (rejecting a valid instance).
     *
     * Both directions and both kinds of collision in one generation run (the files are generated in name order): a
     * nested class against the class of the root schema of another file (AStrictRoot before BOuterLoose) and a nested
     * class against another nested class (CPropertyLoose before DPropertyStrict).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNestedObjectClassesDifferingOnlyInUnevaluatedPropertiesAreDifferentClasses(): void
    {
        $logger = new RecordingLogger();

        $this->generateDirectory(
            'NestedUnevaluatedPropertiesPair',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('NestedUnevaluatedPropertiesPair')
                ->setLogger($logger)
                ->setCollectErrors(false),
        );
        $this->assertNoDuplicatedSignatureNotice($logger);

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;

        // A loose nested schema generated after a strict root with the same declarations must not gain the keyword.
        $outer = new ("\\{$namespacePrefix}\\BOuterLoose")(['p' => ['a' => 'x', 'zz' => 1]]);
        $this->assertSame(['p' => ['a' => 'x', 'zz' => 1]], $outer->meta()->rawInput());
        $this->assertNotSame("{$namespacePrefix}\\AStrictRoot", $outer->getP()::class);

        // A loose nested schema generated before a strict one with the same declarations must stay loose.
        $loose = new ("\\{$namespacePrefix}\\CPropertyLoose")(['p' => ['b' => 'x', 'zz' => 1]]);
        $this->assertSame(['p' => ['b' => 'x', 'zz' => 1]], $loose->meta()->rawInput());

        // The strict schema must not lose the keyword to the loose one generated before it.
        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(<<<MSG
        Invalid nested object for property 'p':
          - Provided JSON for 'DPropertyStrict_P' contains not allowed unevaluated properties ['zz']
        MSG);

        new ("\\{$namespacePrefix}\\DPropertyStrict")(['p' => ['b' => 'x', 'zz' => 1]]);
    }

    /**
     * The composition branch of a schema is a nested class of its own. Two branches which differ only in
     * `unevaluatedProperties` are different classes, otherwise the strict branch would silently lose the keyword and
     * the schema would accept an instance the keyword must reject.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testCompositionBranchClassesDifferingOnlyInUnevaluatedPropertiesAreDifferentClasses(): void
    {
        $logger = new RecordingLogger();

        $this->generateDirectory(
            'BranchUnevaluatedPropertiesPair',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('BranchUnevaluatedPropertiesPair')
                ->setLogger($logger)
                ->setCollectErrors(false),
        );
        $this->assertNoDuplicatedSignatureNotice($logger);

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;

        $loose = new ("\\{$namespacePrefix}\\ALoose")(['c' => 's', 'zz' => 1]);
        $this->assertSame(['c' => 's', 'zz' => 1], $loose->meta()->rawInput());

        // The branch class name carries a hash of the branch schema, which cannot be predicted here.
        $this->expectException(AllOfException::class);
        $this->expectExceptionMessageMatches(<<<'MSG'
        /^Invalid value for 'BStrict' declined by composition constraint
          Requires to match all composition elements but matched 0 elements
          - Composition element #1: Failed
            \* Provided JSON for 'BStrict_Branch[0-9a-f]{32}' contains not allowed unevaluated properties \['zz'\]$/
        MSG);

        new ("\\{$namespacePrefix}\\BStrict")(['c' => 's', 'zz' => 1]);
    }

    /**
     * A property composed of several object schemas is merged into one class, which is deduplicated through a second
     * registry fed by the same signature. A merged schema with a sibling `unevaluatedProperties` must not be
     * redirected to the merged class of the same composition without it.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testMergedPropertyClassesDifferingOnlyInUnevaluatedPropertiesAreDifferentClasses(): void
    {
        $logger = new RecordingLogger();

        $this->generateDirectory(
            'MergedUnevaluatedPropertiesPair',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('MergedUnevaluatedPropertiesPair')
                ->setLogger($logger)
                ->setCollectErrors(false),
        );
        // The merged classes of the two files share identical inner classes, which are redirected to one class as
        // intended; only the merged class itself must keep its own.
        $this->assertNoDuplicatedSignatureNotice($logger, ['class' => 'BMergedStrict_P']);

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;

        $loose = new ("\\{$namespacePrefix}\\AMergedLoose")(['p' => ['d' => 'x', 'e' => 'y', 'zz' => 1]]);
        $this->assertSame(['p' => ['d' => 'x', 'e' => 'y', 'zz' => 1]], $loose->meta()->rawInput());

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(<<<MSG
        Invalid nested object for property 'p':
          - Provided JSON for 'BMergedStrict_P' contains not allowed unevaluated properties ['zz']
        MSG);

        new ("\\{$namespacePrefix}\\BMergedStrict")(['p' => ['d' => 'x', 'e' => 'y', 'zz' => 1]]);
    }

    /**
     * The array-side keyword is read by the post processor from the node's own JSON, which makes the class of a
     * nested object schema declaring `unevaluatedItems` differ from one without it: the evaluation trait is only
     * attached where the keyword is present. No validation outcome of the object class changes, so the difference is
     * asserted on the class itself.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNestedObjectClassesDifferingOnlyInUnevaluatedItemsAreDifferentClasses(): void
    {
        $logger = new RecordingLogger();

        $this->generateDirectory(
            'NestedUnevaluatedItemsPair',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('NestedUnevaluatedItemsPair')
                ->setLogger($logger)
                ->setCollectErrors(false),
        );
        $this->assertNoDuplicatedSignatureNotice($logger);

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;

        $absent = (new ("\\{$namespacePrefix}\\AItemsAbsent")(['p' => ['f' => 'x']]))->getP();
        $declared = (new ("\\{$namespacePrefix}\\BItemsDeclared")(['p' => ['f' => 'x']]))->getP();

        $this->assertNotSame($absent::class, $declared::class);
        $this->assertNotContains(CompositionEvaluationTrait::class, class_uses($absent));
        $this->assertContains(CompositionEvaluationTrait::class, class_uses($declared));
    }

    /**
     * KNOWN DEFECT, pinned on purpose. The signature of a nested schema hashes a relative `$ref` as the bare string,
     * but `#/definitions/d` names a different schema in every file. The nested schema `p` is textually identical in
     * AStrictDefinition.json and BLooseDefinition.json, only the definition it points to differs, so the second is
     * redirected to the class of the first and validated against a definition from another file.
     *
     * Spec-wise BLooseDefinition accepts `{"p": {"optional": "x"}}`; it is rejected here for a missing `must`, which
     * only the other file's definition requires. This test pins that wrong outcome so the gap stays visible until it
     * is fixed: when it is, the redirect notice disappears, the instance is accepted and this test fails — flip the
     * assertions to the spec outcome then.
     */
    public function testKnownDefectNestedSchemasWithIdenticalRefTextButDifferentDefinitionsShareOneClass(): void
    {
        $logger = new RecordingLogger();

        $this->generateDirectory(
            'NestedRefsWithDifferentDefinitions',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('NestedRefsWithDifferentDefinitions')
                ->setLogger($logger)
                ->setCollectErrors(false),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $logger->getEntries(),
                'notice',
                'Duplicated signature {signature} for class {class}. Redirecting to {redirectClass}',
                ['class' => 'BLooseDefinition_P', 'redirectClass' => 'AStrictDefinition_P'],
            ),
            'The nested schema of the second file is redirected to the class of the first.',
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;

        $strict = new ("\\{$namespacePrefix}\\AStrictDefinition")(['p' => ['must' => 'x']]);
        $this->assertSame(['p' => ['must' => 'x']], $strict->meta()->rawInput());

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(<<<MSG
        Invalid nested object for property 'p':
          - Invalid value for 'AStrictDefinition_P' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Missing required value for 'must'
        MSG);

        new ("\\{$namespacePrefix}\\BLooseDefinition")(['p' => ['optional' => 'x']]);
    }

    /**
     * @param array<string, string> $context Narrows the check to the redirect of one class. Needed where the compared
     *                                       schemas legitimately share identical inner classes, which are redirected
     *                                       to one class as intended.
     */
    private function assertNoDuplicatedSignatureNotice(RecordingLogger $logger, array $context = []): void
    {
        $this->assertFalse(
            $this->hasLogEntry(
                $logger->getEntries(),
                'notice',
                'Duplicated signature {signature} for class {class}. Redirecting to {redirectClass}',
                $context,
            ),
            'Schemas differing in an unevaluated keyword are not identical and must not be redirected to one class.',
        );
    }
}
