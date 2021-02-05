<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchemaTrait;

/**
 * Class AbstractProperty
 *
 * @package PHPModelGenerator\Model\Property
 */
abstract class AbstractProperty implements PropertyInterface
{
    use JsonSchemaTrait;

    /** @var string */
    protected $name = '';
    /** @var string */
    protected $attribute = '';

    /**
     * Property constructor.
     *
     * @param string $name
     * @param JsonSchema $jsonSchema
     *
     * @throws SchemaException
     */
    public function __construct(string $name, JsonSchema $jsonSchema)
    {
        $this->name = $name;
        $this->jsonSchema = $jsonSchema;

        $this->attribute = $this->processAttributeName($name);
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
    public function getAttribute(): string
    {
        return ($this->isInternal() ? '_' : '') . $this->attribute;
    }

    /**
     * Convert a name of a JSON-field into a valid PHP variable name to be used as class attribute
     *
     * @param string $name
     *
     * @return string
     *
     * @throws SchemaException
     */
    protected function processAttributeName(string $name): string
    {
        $attributeName = preg_replace_callback(
            '/([a-z][a-z0-9]*)([A-Z])/',
            function ($matches) {
                return "{$matches[1]}-{$matches[2]}";
            },
            $name
        );

        $elements = array_map(
            function ($element) {
                return ucfirst(strtolower($element));
            },
            preg_split('/[^a-z0-9]/i', $attributeName)
        );

        $attributeName = lcfirst(join('', $elements));

        if (empty($attributeName)) {
            throw new SchemaException(
                sprintf(
                    "Property name '%s' results in an empty attribute name in file %s",
                    $name,
                    $this->jsonSchema->getFile()
                )
            );
        }

        return $attributeName;
    }
}
