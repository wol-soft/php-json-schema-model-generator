<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #199: the class name of a nested object generated for a composition branch embedded the
 * complete class name of its parent again at every nesting level, because the name of the root
 * property of a schema (which is the class name of the schema) was used as the base name of the
 * branch classes. The length of the generated class names and file names therefore grew linearly
 * with the nesting depth and generation failed with a FileSystemException as soon as the file name
 * exceeded the file system limit.
 *
 * The test case generator suffixes the root class and every nested class with a uniqid, so the
 * class names asserted here are those of the default class name generator plus that suffix.
 */
class Issue199Test extends AbstractIssueTestCase
{
    /** Longest file name which is portable across common file systems, excluding the ".php" suffix */
    private const int MAX_CLASS_NAME_LENGTH = 251;

    /**
     * A deep chain of nested allOf compositions must be generated, and the class names of the
     * branch classes must not depend on the nesting depth: the same root class name yields
     * identically long class names for two levels and for eight levels. Every branch class is named
     * after the root class, the fixed branch name and the content hash of the branch.
     */
    public function testClassNamesOfNestedAllOfBranchesAreIndependentOfTheNestingDepth(): void
    {
        $this->generateClassFromFile('NestedAllOfDepth2.json');
        $shallowClassNameLengths = $this->getGeneratedClassNameLengths();

        $deepClassName = $this->generateClassFromFile('NestedAllOfDepth8.json');
        $deepClassNames = $this->getGeneratedClassNames();

        $this->assertLessThanOrEqual(self::MAX_CLASS_NAME_LENGTH, max($this->getGeneratedClassNameLengths()));
        $this->assertSame(max($shallowClassNameLengths), max($this->getGeneratedClassNameLengths()));
        $this->assertBranchClassNames($deepClassNames, 7);

        $object = new $deepClassName(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        $this->expectException(ValidationException::class);
        new $deepClassName([]);
    }

    /**
     * Alternating oneOf / anyOf / allOf compositions where every level carries a sibling branch
     * must be generated with bounded class names as well.
     */
    public function testClassNamesOfNestedMixedCompositionBranchesAreBounded(): void
    {
        $className = $this->generateClassFromFile('NestedMixedComposition.json');

        $this->assertLessThanOrEqual(self::MAX_CLASS_NAME_LENGTH, max($this->getGeneratedClassNameLengths()));
        $this->assertBranchClassNames($this->getGeneratedClassNames(), 12);

        // matches the first level sibling branch only
        $this->assertInstanceOf($className, new $className(['marker0' => 'a']));

        // matches only the nested branches of the first levels and the sibling branch of the fourth level
        $this->assertInstanceOf($className, new $className(['marker2' => 'a', 'marker3' => 'b']));

        $this->expectException(ValidationException::class);
        new $className([]);
    }

    /**
     * The branches of a composition below a property keep the name of that property in their class
     * name ("<root>_Order<md5><uniqid>"), exactly like the branches of a composition declared
     * directly on the property, and the name doesn't grow with the nesting depth. A branch which
     * carries a title is named by its title and not by the property.
     */
    public function testClassNamesOfNestedBranchesBelowAPropertyContainThePropertyName(): void
    {
        $className = $this->generateClassFromFile('PropertyNestedAllOf.json');
        $classNames = $this->getGeneratedClassNames();

        $uniqid = '[0-9a-f]{13}';
        $root = preg_quote($className, '/');
        $expectedPatterns = [
            "/^{$root}$/",
            "/^{$root}_Order$uniqid$/",
            "/^{$root}_Order[0-9a-f]{32}$uniqid$/",
            "/^{$root}_Leaf$uniqid$/",
        ];

        $matchedPatterns = [];
        foreach ($classNames as $generatedClassName) {
            foreach ($expectedPatterns as $index => $pattern) {
                if (preg_match($pattern, $generatedClassName)) {
                    $matchedPatterns[$index] = ($matchedPatterns[$index] ?? 0) + 1;
                    continue 2;
                }
            }

            $this->fail("Unexpected class name $generatedClassName");
        }

        // root class, the class of the property, four untitled branches and the titled branch
        ksort($matchedPatterns);
        $this->assertSame([0 => 1, 1 => 1, 2 => 4, 3 => 1], $matchedPatterns);
        $this->assertLessThanOrEqual(self::MAX_CLASS_NAME_LENGTH, max($this->getGeneratedClassNameLengths()));

        $object = new $className(['order' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getOrder()->getName());

        $this->expectException(ValidationException::class);
        new $className(['order' => []]);
    }

    /**
     * Every class except the root class is a branch class named "<root>_Branch<md5><uniqid>".
     *
     * @param string[] $classNames
     */
    private function assertBranchClassNames(array $classNames, int $expectedBranchClasses): void
    {
        $uniqid = '[0-9a-f]{13}';
        $rootClassName = '';
        $branchClassNames = [];

        foreach ($classNames as $className) {
            if (preg_match("/^{$this->getStaticClassName()}_{$uniqid}$/", $className)) {
                $rootClassName = $className;
                continue;
            }

            $branchClassNames[] = $className;
        }

        $this->assertNotSame('', $rootClassName);
        $this->assertCount($expectedBranchClasses, $branchClassNames);

        foreach ($branchClassNames as $branchClassName) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($rootClassName, '/') . "_Branch[0-9a-f]{32}$uniqid$/",
                $branchClassName,
            );
        }
    }

    /**
     * @return string[] The class names (file names without extension) generated by the last
     *                  generation run
     */
    private function getGeneratedClassNames(): array
    {
        return array_map(
            static fn(string $file): string => basename($file, '.php'),
            glob(MODEL_TEMP_PATH . '/*.php'),
        );
    }

    /**
     * @return int[]
     */
    private function getGeneratedClassNameLengths(): array
    {
        return array_map('strlen', $this->getGeneratedClassNames());
    }
}
