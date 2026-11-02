<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use PHPModelGenerator\Accessor\Meta;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\Validator\AbstractPropertyValidator;
use PHPModelGenerator\Tests\Fixtures\JsonValueEqualityCases;
use PHPModelGenerator\Utils\JsonValueCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes the expressions which are embedded into the generated code. The generated code must come to the same
 * result as the comparison used while the code is generated.
 */
class JsonValueCheckTest extends TestCase
{
    #[DataProvider('equalityCasesDataProvider')]
    public function testNotOneOf(mixed $provided, mixed $allowed, bool $expected): void
    {
        // the order of the allowed values doesn't matter, the provided value is looked up in all of them
        foreach ([[$allowed, 'other'], ['other', $allowed], [$allowed]] as $allowedValues) {
            $this->assertSame(!$expected, $this->evaluate(JsonValueCheck::notOneOf($allowedValues), $provided));
        }
    }

    #[DataProvider('equalityCasesDataProvider')]
    public function testNotEqualTo(mixed $provided, mixed $allowed, bool $expected): void
    {
        $this->assertSame(!$expected, $this->evaluate(JsonValueCheck::notEqualTo($allowed), $provided));
    }

    public static function equalityCasesDataProvider(): array
    {
        return JsonValueEqualityCases::cases();
    }

    public function testNotOneOfOfScalarsIsAStrictMembershipCheck(): void
    {
        $this->assertSame(
            '!in_array($value, [\'a\', 1, true, null], true)',
            JsonValueCheck::notOneOf(['a', 1, true, null]),
        );
        $this->assertSame('$value !== \'a\'', JsonValueCheck::notEqualTo('a'));
        $this->assertSame('$value !== null', JsonValueCheck::notEqualTo(null));
    }

    public function testStructuresAreComparedWithAClosure(): void
    {
        $checks = [JsonValueCheck::notOneOf(['a', ['b' => 1]]), JsonValueCheck::notEqualTo(['b' => 1])];

        foreach ($checks as $check) {
            $this->assertStringStartsWith('!(static function (mixed $provided, array $allowedValues): bool {', $check);
            $this->assertStringContainsString('[\'b\' => 1]', $check);
        }
    }

    public function testModelsAreComparedWithTheirRawInput(): void
    {
        $model = $this->createModel(['x' => 'y', 'n' => 1]);
        $nestedModel = $this->createModel(['inner' => $model, 'list' => [$this->createModel([])]]);

        $check = JsonValueCheck::notOneOf([['n' => 1.0, 'x' => 'y'], 'other']);
        $this->assertFalse($this->evaluate($check, $model));
        $this->assertTrue($this->evaluate($check, $this->createModel(['x' => 'z', 'n' => 1])));
        $this->assertTrue($this->evaluate($check, $this->createModel([])));

        // models inside of structures and models inside of the raw input of a model
        $this->assertFalse($this->evaluate(
            JsonValueCheck::notEqualTo(['inner' => ['x' => 'y', 'n' => 1], 'list' => [[]]]),
            $nestedModel,
        ));
        $this->assertFalse($this->evaluate(
            JsonValueCheck::notEqualTo([['x' => 'y', 'n' => 1], []]),
            [$model, $this->createModel([])],
        ));
        $this->assertTrue($this->evaluate(JsonValueCheck::notEqualTo([['x' => 'y']]), [$model]));
    }

    public function testProvidedValueOfStructuresIsTheRawInputOfTheModels(): void
    {
        $model = $this->createModel(['x' => 'y', 'inner' => $this->createModel(['n' => 1])]);

        $code = JsonValueCheck::providedValue([['x' => 'y'], null]);

        $this->assertSame(['x' => 'y', 'inner' => ['n' => 1]], $this->evaluate($code, $model));
        $this->assertSame(
            [['x' => 'y'], ['a' => [1, 'b']]],
            $this->evaluate($code, [$this->createModel(['x' => 'y']), ['a' => [1, 'b']]]),
        );
        $this->assertSame('text', $this->evaluate($code, 'text'));
        $this->assertNull($this->evaluate($code, null));
        $this->assertSame([], $this->evaluate($code, []));
    }

    public function testProvidedValueOfScalarsIsTheValue(): void
    {
        $this->assertSame(
            AbstractPropertyValidator::VALIDATED_VALUE_CODE,
            JsonValueCheck::providedValue(['a', 1, null]),
        );
    }

    private function evaluate(string $code, mixed $value): mixed
    {
        return eval('return ' . $code . ';');
    }

    private function createModel(array $rawInput): JSONModelInterface
    {
        return new class ($rawInput) implements JSONModelInterface {
            public function __construct(private array $rawInput)
            {
            }

            public function meta(): Meta
            {
                return new Meta($this->rawInput);
            }
        };
    }
}
