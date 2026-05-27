<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use UnitEnum;

class Issue137Test extends AbstractIssueTestCase
{
    private function installEnumPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };
    }

    /**
     * Find the generated enum class name among a list of type names by matching the configured
     * Enum namespace prefix. Returns null when no enum class is present (i.e. the bug case).
     */
    private function findEnumClassName(array $typeNames): ?string
    {
        foreach ($typeNames as $name) {
            if (str_starts_with($name, 'Enum\\')) {
                return $name;
            }
        }
        return null;
    }

    /**
     * A oneOf with a single-enum branch and an array-of-the-same-enum branch must propagate the
     * generated enum class into both the setter's native parameter type AND its `@param` annotation,
     * and the getter must return the same union. The fix also enables passing an enum instance
     * to the setter at runtime — which the previous `string|array|null` signature rejected with
     * a TypeError.
     *
     * Combines: setter native type, setter @param annotation, getter return type, getter @return
     * annotation, runtime round-trip with both branches — all on the same generated class.
     */
    public function testOneOfWithEnumAndArrayOfEnumPropagatesEnumClass(): void
    {
        $this->installEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'oneOfEnumOrEnumArray.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Discover the generated enum class (its name depends on the schema's randomised class
        // name suffix) by scanning the setter parameter types for an `Enum\` prefix.
        $parameterTypeNames = $this->getParameterTypeNames($className, 'setStatusFilter');
        $enumClassName = $this->findEnumClassName($parameterTypeNames);
        $this->assertNotNull(
            $enumClassName,
            'Native setter parameter type must include the generated enum class '
            . '(actual: ' . implode(' | ', $parameterTypeNames) . ')',
        );
        $shortEnumName = substr($enumClassName, strrpos($enumClassName, '\\') + 1);

        // -- Setter parameter (native type) — exact union, no extras, no omissions --
        $this->assertEqualsCanonicalizing(
            [$enumClassName, 'string', 'array', 'null'],
            $parameterTypeNames,
        );

        // -- Setter parameter (PHPDoc) — exact union including the array-of-enum form --
        $parameterTypeAnnotation = $this->getParameterTypeAnnotation($className, 'setStatusFilter');
        $this->assertEqualsCanonicalizing(
            ['string', $shortEnumName, 'string[]', "{$shortEnumName}[]", 'null'],
            explode('|', $parameterTypeAnnotation),
            "Setter @param mismatch (actual: $parameterTypeAnnotation)",
        );

        // -- Getter return (native) — same union as the setter parameter, since
        // transferPropertyType produces a single PropertyType applied to both sides.
        $returnTypeNames = $this->getReturnTypeNames($className, 'getStatusFilter');
        $this->assertEqualsCanonicalizing(
            [$enumClassName, 'string', 'array', 'null'],
            $returnTypeNames,
        );

        // -- Getter return (PHPDoc) — output side: enum + enum[] + null --
        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getStatusFilter');
        $this->assertEqualsCanonicalizing(
            [$shortEnumName, "{$shortEnumName}[]", 'null'],
            explode('|', $returnAnnotation),
            "Getter @return mismatch (actual: $returnAnnotation)",
        );

        // -- Runtime round-trip: single enum value (string in → enum out via branch 0) --
        $singleViaString = new $className(['status_filter' => 'active']);
        $singleResult = $singleViaString->getStatusFilter();
        $this->assertInstanceOf(UnitEnum::class, $singleResult);
        $this->assertSame('active', $singleResult->value);

        // -- Runtime round-trip: array branch (array of strings in → array of enums out) --
        $arrayViaStrings = new $className(['status_filter' => ['active', 'paused']]);
        $arrayResult = $arrayViaStrings->getStatusFilter();
        $this->assertIsArray($arrayResult);
        $this->assertCount(2, $arrayResult);
        $this->assertContainsOnlyInstancesOf(UnitEnum::class, $arrayResult);
        $this->assertSame(['active', 'paused'], array_map(static fn(UnitEnum $case) => $case->value, $arrayResult));
    }

    /**
     * Same shape as the oneOf case but with anyOf — must produce the same widened type
     * (any branch matching is sufficient, so the union of branch types still applies).
     */
    public function testAnyOfWithEnumAndArrayOfEnumPropagatesEnumClass(): void
    {
        $this->installEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'anyOfEnumOrEnumArray.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $parameterTypeNames = $this->getParameterTypeNames($className, 'setStatusFilter');
        $enumClassName = $this->findEnumClassName($parameterTypeNames);
        $this->assertNotNull(
            $enumClassName,
            'anyOf must propagate the enum class to the setter type '
            . '(actual: ' . implode(' | ', $parameterTypeNames) . ')',
        );
        $this->assertEqualsCanonicalizing(
            [$enumClassName, 'string', 'array', 'null'],
            $parameterTypeNames,
        );
    }

    /**
     * Inline enum (no $ref) inside a oneOf branch must be promoted to a generated enum class
     * exactly like the $ref case, with the same type propagation.
     */
    public function testInlineEnumInOneOfBranchProducesEnumClass(): void
    {
        $this->installEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'oneOfInlineEnumOrEnumArray.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $parameterTypeNames = $this->getParameterTypeNames($className, 'setStatusFilter');
        $enumClassName = $this->findEnumClassName($parameterTypeNames);
        $this->assertNotNull(
            $enumClassName,
            'Inline enum must still be generated and included in the setter type '
            . '(actual: ' . implode(' | ', $parameterTypeNames) . ')',
        );
        $this->assertEqualsCanonicalizing(
            [$enumClassName, 'string', 'array', 'null'],
            $parameterTypeNames,
        );
    }

    /**
     * In immutable mode there is no setter; the type still needs to surface on the getter so
     * static analysis and IDEs work. Verifies that the enum class is reachable via the getter
     * return type when no setter is generated.
     */
    public function testImmutableModeExposesEnumClassOnGetter(): void
    {
        $this->installEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'oneOfEnumOrEnumArray.json',
            (new GeneratorConfiguration())->setImmutable(true),
        );

        $returnTypeNames = $this->getReturnTypeNames($className, 'getStatusFilter');
        $enumClassName = $this->findEnumClassName($returnTypeNames);
        $this->assertNotNull(
            $enumClassName,
            'Native getter return type must include the generated enum class in immutable mode '
            . '(actual: ' . implode(' | ', $returnTypeNames) . ')',
        );
        $this->assertEqualsCanonicalizing(
            [$enumClassName, 'string', 'array', 'null'],
            $returnTypeNames,
        );
    }
}
