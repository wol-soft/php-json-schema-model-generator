<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

/**
 * Class GeneratorConfiguration
 *
 * @package PHPModelGenerator\Model
 */
class GeneratorConfiguration
{
    /** @var string */
    protected $namespacePrefix = '';
    /** @var bool */
    protected $immutable = false;

    /**
     * @return string
     */
    public function getNamespacePrefix(): string
    {
        return $this->namespacePrefix;
    }

    /**
     * @param string $namespacePrefix
     *
     * @return GeneratorConfiguration
     */
    public function setNamespacePrefix(string $namespacePrefix): GeneratorConfiguration
    {
        $this->namespacePrefix = $namespacePrefix;
        return $this;
    }

    /**
     * @return bool
     */
    public function isImmutable(): bool
    {
        return $this->immutable;
    }

    /**
     * @param bool $immutable
     *
     * @return GeneratorConfiguration
     */
    public function setImmutable(bool $immutable): GeneratorConfiguration
    {
        $this->immutable = $immutable;
        return $this;
    }
}
