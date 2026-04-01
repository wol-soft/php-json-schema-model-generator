<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\PhpAttribute;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Class PhpAttributeTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PhpAttributeTest extends AbstractPHPModelGeneratorTestCase
{
    // Fake FQCNs used only for import and rendering tests; no instantiation.
    private const FQCN_CLASS_ATTR    = 'Some\\External\\ClassAttr';
    private const FQCN_PROPERTY_ATTR = 'Some\\External\\PropertyAttr';
    private const FQCN_COLUMN_ATTR   = 'Some\\External\\Column';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function addClassAttribute(PhpAttribute $attribute): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($attribute): void {
            $generator->addPostProcessor(new class ($attribute) extends PostProcessor {
                public function __construct(private readonly PhpAttribute $attribute) {}

                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    $schema->addAttribute($this->attribute);
                }
            });
        };
    }

    private function addPropertyAttribute(string $propertyName, PhpAttribute $attribute): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use (
            $propertyName,
            $attribute,
        ): void {
            $generator->addPostProcessor(new class ($propertyName, $attribute) extends PostProcessor {
                public function __construct(
                    private readonly string $propertyName,
                    private readonly PhpAttribute $attribute,
                ) {}

                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    foreach ($schema->getProperties() as $property) {
                        if ($property->getName() === $this->propertyName) {
                            $property->addAttribute($this->attribute);
                        }
                    }
                }
            });
        };
    }

    private function getGeneratedSource(): string
    {
        return file_get_contents($this->getGeneratedFiles()[0]);
    }

    // -------------------------------------------------------------------------
    // Class-level attributes
    // -------------------------------------------------------------------------

    public function testClassAttributeWithoutArguments(): void
    {
        $this->addClassAttribute(new PhpAttribute(self::FQCN_CLASS_ATTR));
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString('#[ClassAttr]', $source);
        $this->assertStringContainsString('use Some\\External\\ClassAttr;', $source);
    }

    public function testClassAttributeWithPositionalArgument(): void
    {
        $this->addClassAttribute(new PhpAttribute(self::FQCN_CLASS_ATTR, ["'my-value'"]));
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString("#[ClassAttr('my-value')]", $source);
        $this->assertStringContainsString('use Some\\External\\ClassAttr;', $source);
    }

    public function testClassAttributeWithNamedArguments(): void
    {
        $this->addClassAttribute(
            new PhpAttribute(self::FQCN_CLASS_ATTR, ['path' => "'/api/resource'", 'methods' => "['GET']"]),
        );
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString("#[ClassAttr(path: '/api/resource', methods: ['GET'])]", $source);
        $this->assertStringContainsString('use Some\\External\\ClassAttr;', $source);
    }

    public function testMultipleClassAttributes(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    $schema->addAttribute(new PhpAttribute('Some\\External\\ClassAttr'));
                    $schema->addAttribute(new PhpAttribute('Some\\External\\PropertyAttr', ['name' => "'test'"]));
                }
            });
        };

        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString('#[ClassAttr]', $source);
        $this->assertStringContainsString("#[PropertyAttr(name: 'test')]", $source);
        $this->assertStringContainsString('use Some\\External\\ClassAttr;', $source);
        $this->assertStringContainsString('use Some\\External\\PropertyAttr;', $source);
    }

    // -------------------------------------------------------------------------
    // Property-level attributes
    // -------------------------------------------------------------------------

    public function testPropertyAttributeWithoutArguments(): void
    {
        $this->addPropertyAttribute('name', new PhpAttribute(self::FQCN_PROPERTY_ATTR));
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString('#[PropertyAttr]', $source);
        $this->assertStringContainsString('use Some\\External\\PropertyAttr;', $source);
    }

    public function testPropertyAttributeWithNamedArguments(): void
    {
        $this->addPropertyAttribute(
            'name',
            new PhpAttribute(self::FQCN_COLUMN_ATTR, ['name' => "'full_name'", 'nullable' => 'false']),
        );
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString("#[Column(name: 'full_name', nullable: false)]", $source);
        $this->assertStringContainsString('use Some\\External\\Column;', $source);
    }

    public function testMultiplePropertiesEachWithAttribute(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    foreach ($schema->getProperties() as $property) {
                        $property->addAttribute(
                            new PhpAttribute('Some\\External\\Column', ['name' => "'" . $property->getName() . "'"]),
                        );
                    }
                }
            });
        };

        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString("#[Column(name: 'name')]", $source);
        $this->assertStringContainsString("#[Column(name: 'age')]", $source);
        // Only one import despite the same FQCN on multiple properties
        $this->assertSame(1, substr_count($source, 'use Some\\External\\Column;'));
    }

    // -------------------------------------------------------------------------
    // Combined class + property attributes
    // -------------------------------------------------------------------------

    public function testClassAndPropertyAttributesCombined(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    $schema->addAttribute(new PhpAttribute('Some\\External\\ClassAttr'));

                    foreach ($schema->getProperties() as $property) {
                        if ($property->getName() === 'name') {
                            $property->addAttribute(new PhpAttribute('Some\\External\\Column'));
                        }
                    }
                }
            });
        };

        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString('#[ClassAttr]', $source);
        $this->assertStringContainsString('#[Column]', $source);
        $this->assertStringContainsString('use Some\\External\\ClassAttr;', $source);
        $this->assertStringContainsString('use Some\\External\\Column;', $source);
    }

    // -------------------------------------------------------------------------
    // Import deduplication
    // -------------------------------------------------------------------------

    public function testSameFqcnOnClassAndPropertyGeneratesOneImport(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    $schema->addAttribute(new PhpAttribute('Some\\External\\ClassAttr'));

                    foreach ($schema->getProperties() as $property) {
                        $property->addAttribute(new PhpAttribute('Some\\External\\ClassAttr'));
                    }
                }
            });
        };

        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $this->assertSame(1, substr_count($source, 'use Some\\External\\ClassAttr;'));
    }

    // -------------------------------------------------------------------------
    // Namespace filtering
    // -------------------------------------------------------------------------

    public function testAttributeInSameNamespaceGeneratesNoImport(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $config): void
                {
                    // FQCN matches the namespace prefix configured below
                    $schema->addAttribute(new PhpAttribute('MyApp\\Model\\SameNsAttr'));
                }
            });
        };

        $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setNamespacePrefix('MyApp\\Model'),
        );

        $source = $this->getGeneratedSource();

        $this->assertStringContainsString('#[SameNsAttr]', $source);
        $this->assertStringNotContainsString('use MyApp\\Model\\SameNsAttr;', $source);
    }

    // -------------------------------------------------------------------------
    // Attribute placement in generated source
    // -------------------------------------------------------------------------

    public function testClassAttributeAppearsBeforeClassKeyword(): void
    {
        $this->addClassAttribute(new PhpAttribute(self::FQCN_CLASS_ATTR));
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        // The #[ClassAttr] line must come before the 'class ClassName' declaration line.
        // Use "\nclass " to match the class keyword at the start of a line, not occurrences
        // inside docblock comments (e.g. "auto-implemented class implemented by...").
        $attrPos  = strpos($source, '#[ClassAttr]');
        $classPos = strpos($source, "\nclass ");

        $this->assertNotFalse($attrPos);
        $this->assertNotFalse($classPos);
        $this->assertLessThan($classPos, $attrPos);
    }

    public function testPropertyAttributeAppearsBeforePropertyDeclaration(): void
    {
        $this->addPropertyAttribute('name', new PhpAttribute(self::FQCN_PROPERTY_ATTR));
        $this->generateClassFromFile('BasicSchema.json');

        $source = $this->getGeneratedSource();

        $attrPos  = strpos($source, '#[PropertyAttr]');
        $propPos  = strpos($source, '$name');

        $this->assertNotFalse($attrPos);
        $this->assertNotFalse($propPos);
        $this->assertLessThan($propPos, $attrPos);
    }
}
