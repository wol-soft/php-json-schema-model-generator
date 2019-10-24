<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AdditionalItemsValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class AdditionalItemsValidator extends AdditionalPropertiesValidator
{
    protected const PROPERTY_NAME = 'additional item';

    protected const PROPERTIES_KEY = 'items';
    protected const ADDITIONAL_PROPERTIES_KEY = 'additionalItems';

    /** @var string */
    private $propertyName;

    /**
     * AdditionalItemsValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     * @param array           $propertiesStructure
     * @param string          $propertyName
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        array $propertiesStructure,
        string $propertyName
    ) {
        $this->propertyName = $propertyName;

        parent::__construct($schemaProcessor, $schema, $propertiesStructure);
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '
            $properties = $value;
            $invalidProperties = [];
        ';
    }

    protected function getErrorMessage(): string
    {
        return "Tuple array {$this->propertyName} contains invalid additional items.";
    }
}
