<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Attributes\AttributesTrait;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchemaTrait;
use PHPModelGenerator\Utils\NormalizedName;
use PHPModelGenerator\Utils\ResolvableTrait;

/**
 * Class AbstractProperty
 *
 * @package PHPModelGenerator\Model\Property
 */
abstract class AbstractProperty implements PropertyInterface
{
    use JsonSchemaTrait;
    use ResolvableTrait;
    use AttributesTrait;

    protected string $attribute;

    /**
     * Property constructor.
     *
     * @throws SchemaException
     */
    public function __construct(protected string $name, JsonSchema $jsonSchema)
    {
        $this->jsonSchema = $jsonSchema;

        $this->attribute = $this->processAttributeName($this->name);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getAttribute(bool $variableName = false): string
    {
        if (!$this->isInternal() && $variableName && preg_match('/^\d/', $this->attribute) === 1) {
            return 'numeric_property_' . $this->attribute;
        }

        return $this->attribute;
    }

    /**
     * Convert a name of a JSON-field into a valid PHP variable name to be used as class attribute
     *
     * @throws SchemaException
     */
    protected function processAttributeName(string $name): string
    {
        return lcfirst(NormalizedName::from($name, $this->jsonSchema));
    }
}
