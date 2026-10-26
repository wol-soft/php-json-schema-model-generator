<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProvider\OpenAPIv3Provider;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A schema file whose root carries object keywords but no type, no composition and no reference describes an object
 * without saying so: the missing type is easy to forget. A class can't pass a non-object value through, so unlike the
 * schemas at the other sites the root is not accepted by default. No class is generated and the generator warns.
 * Enabling setImplicitObjectComposition() generates the class as if the type was declared.
 */
#[ApplicableDrafts]
class ObjectDescribingRootTest extends AbstractPHPModelGeneratorTestCase
{
    private const SKIPPED_ROOT_WARNING = "Schema for '{class}' in file '{file}' carries object-constraining keywords"
        . " (eg. 'properties', 'required') without a 'type' declaration and no class is generated for it: add an"
        . " explicit '\"type\": \"object\"' constraint, or enable"
        . " 'GeneratorConfiguration::setImplicitObjectComposition(true)' to generate it";

    private const SITE_WARNING = "Property '{property}' at '{pointer}' in file '{file}' carries object-constraining"
        . " keywords (eg. 'properties', 'required') without a 'type' declaration and does not constrain non-object"
        . ' values';

    /**
     * The root of the file is skipped: nothing but the warning tells that the file doesn't define a class, which is
     * what a component without the type of an OpenAPI document is easily mistaken for. The warning names the class
     * which would have been generated and the file. The root with the `not` keyword next to the object keywords is
     * judged by the object keywords, a root with `not` alone is not (see
     * testRootWhichDescribesNoObjectIsSkippedSilently).
     */
    #[DataProvider('objectDescribingRootProvider')]
    public function testObjectDescribingRootIsSkippedWithAWarning(string $schemaFile): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $warnings = $this->getWarningContexts($recordingLogger, self::SKIPPED_ROOT_WARNING);

        $this->assertCount(1, $warnings);
        $this->assertSame($className, $warnings[0]['class']);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . $className . '.json', $warnings[0]['file']);
        $this->assertSame([], $this->getGeneratedFiles());
    }

    public static function objectDescribingRootProvider(): array
    {
        return [
            'properties and required' => ['ObjectKeywordsRoot.json'],
            'dependencies' => ['DependenciesRoot.json'],
            'not next to object keywords' => ['NotWithObjectKeywordsRoot.json'],
        ];
    }

    /**
     * `unevaluatedProperties` constrains objects from Draft 2019-09 on only.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testRootWithOnlyUnevaluatedPropertiesIsSkippedWithAWarning(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateClassFromFile(
            'UnevaluatedPropertiesRoot.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertCount(1, $this->getWarningContexts($recordingLogger, self::SKIPPED_ROOT_WARNING));
        $this->assertSame([], $this->getGeneratedFiles());
    }

    /**
     * A root without object keywords is no object description. It holds definitions, describes another type or
     * carries nothing but annotations or a `not`, and has always been skipped without a message: a schema directory
     * routinely contains files which are only meant to be referenced.
     */
    #[DataProvider('rootWhichDescribesNoObjectProvider')]
    public function testRootWhichDescribesNoObjectIsSkippedSilently(string $schemaFile): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertSame([], $this->getWarnings($recordingLogger));
        $this->assertSame([], $this->getGeneratedFiles());
    }

    public static function rootWhichDescribesNoObjectProvider(): array
    {
        return [
            'definitions only' => ['DefinitionsOnlyRoot.json'],
            'another type' => ['ScalarRoot.json'],
            'not only' => ['NotOnlyRoot.json'],
            'annotation only' => ['AnnotationOnlyRoot.json'],
        ];
    }

    /**
     * With the flag the root is accepted as if it declared the object type: the class is generated and validates, and
     * nothing is reported (the flag is the acknowledgement, like it is for a composition).
     */
    public function testObjectDescribingRootGeneratesAClassWithImplicitObjectComposition(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'ObjectKeywordsRoot.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImplicitObjectComposition(true)
                ->setLogger($recordingLogger),
        );

        $this->assertSame([], $this->getWarnings($recordingLogger));

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        try {
            new $className([]);
            $this->fail('Expected a RequiredValueException for the missing name');
        } catch (RequiredValueException $exception) {
            $this->assertSame("Missing required value for 'name'", $exception->getMessage());
        }
    }

    /**
     * A file which describes an object without the type and is referenced by another file is reached twice: as a file
     * of the directory and through the reference. The skipped root is reported once. The property which references
     * the file is a site of its own and warns as every site does.
     */
    public function testReferencedObjectDescribingFileIsReportedOnceAsASkippedRoot(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateDirectory(
            'FragmentReference',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('ObjectDescribingFragmentSkipped')
                ->setCollectErrors(false)
                ->setLogger($recordingLogger),
        );

        $skippedRoots = $this->getWarningContexts($recordingLogger, self::SKIPPED_ROOT_WARNING);

        $this->assertCount(1, $skippedRoots);
        $this->assertSame('ObjectDescribingFragment', $skippedRoots[0]['class']);

        $sites = $this->getWarningContexts($recordingLogger, self::SITE_WARNING);

        $this->assertCount(1, $sites);
        $this->assertSame('fragment', $sites[0]['property']);
    }

    /**
     * With the flag the referenced file is generated as the class of the file and the property of the referencing
     * file uses that very class: no second class is generated for the same schema.
     */
    public function testReferencedObjectDescribingFileSharesItsClassWithImplicitObjectComposition(): void
    {
        $generatedFiles = $this->generateDirectory(
            'FragmentReference',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('ObjectDescribingFragmentGenerated')
                ->setCollectErrors(false)
                ->setImplicitObjectComposition(true),
        );

        $generatedFileNames = array_map('basename', $generatedFiles);
        sort($generatedFileNames);

        $this->assertSame(['ObjectDescribingFragment.php', 'ObjectDescribingFragmentUser.php'], $generatedFileNames);

        $userClass = '\\' . $this->lastGeneratedNamespacePrefix . '\\ObjectDescribingFragmentUser';
        $fragmentClass = '\\' . $this->lastGeneratedNamespacePrefix . '\\ObjectDescribingFragment';

        $this->assertInstanceOf($fragmentClass, (new $userClass(['fragment' => ['id' => 'a']]))->getFragment());
    }

    /**
     * Every component of an OpenAPI document is the root of a class, and a component which describes an object without
     * the type is a common slip. Each skipped component is reported by itself, though all components share the file.
     */
    public function testObjectDescribingComponentsOfAnOpenApiDocumentAreReportedPerComponent(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'OpenApiDocument.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
            false,
            true,
            OpenAPIv3Provider::class,
        );

        $skippedClasses = array_column(
            $this->getWarningContexts($recordingLogger, self::SKIPPED_ROOT_WARNING),
            'class',
        );
        sort($skippedClasses);

        $this->assertSame([$className . '_0', $className . '_1'], $skippedClasses);
        $this->assertSame([$className . '_2.php'], array_map('basename', $this->getGeneratedFiles()));
    }

    public function testObjectDescribingComponentsOfAnOpenApiDocumentGenerateClassesWithTheFlag(): void
    {
        $className = $this->generateClassFromFile(
            'OpenApiDocument.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImplicitObjectComposition(true),
            false,
            true,
            OpenAPIv3Provider::class,
        );

        $generatedFileNames = array_map('basename', $this->getGeneratedFiles());
        sort($generatedFileNames);

        $this->assertSame(
            [$className . '_0.php', $className . '_1.php', $className . '_2.php'],
            $generatedFileNames,
        );
    }

    /**
     * @return array<int, array{level: string, message: string, context: array}>
     */
    private function getWarnings(RecordingLogger $recordingLogger): array
    {
        return array_values(array_filter(
            $recordingLogger->getEntries(),
            static fn(array $entry): bool => $entry['level'] === 'warning',
        ));
    }

    /**
     * @return array<int, array<string, mixed>> the contexts of the warnings which use the given message template
     */
    private function getWarningContexts(RecordingLogger $recordingLogger, string $message): array
    {
        $contexts = [];

        foreach ($recordingLogger->getEntries() as $entry) {
            if ($entry['level'] === 'warning' && $entry['message'] === $message) {
                $contexts[] = $entry['context'];
            }
        }

        return $contexts;
    }
}
