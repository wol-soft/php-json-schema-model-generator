<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Schema;

/**
 * Class BaseProperty
 *
 * @package PHPModelGenerator\Model\Property
 */
class BaseProperty extends Property
{
    /** @var Schema */
    protected $schema;

    /**
     * @return Schema
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * @param Schema $schema
     *
     * @return $this
     */
    public function setSchema(Schema $schema): self
    {
        $this->schema = $schema;

        return $this;
    }
}
