<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;

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
    /** @var bool */
    protected $prettyPrint = true;
    /** @var bool */
    protected $outputEnabled = true;
    /** @var bool */
    protected $collectErrors = true;
    /** @var string */
    protected $errorRegistryClass = ErrorRegistryException::class;
    /** @var string */
    protected $exceptionClass = ValidationException::class;

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
     * @return $this
     */
    public function setNamespacePrefix(string $namespacePrefix): self
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
    public function setImmutable(bool $immutable): self
    {
        $this->immutable = $immutable;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasPrettyPrintEnabled(): bool
    {
        return $this->prettyPrint;
    }

    /**
     * @param bool $prettyPrint
     *
     * @return $this
     */
    public function setPrettyPrint(bool $prettyPrint): self
    {
        $this->prettyPrint = $prettyPrint;
        return $this;
    }

    /**
     * @param bool $outputEnabled
     *
     * @return $this
     */
    public function setOutputEnabled(bool $outputEnabled): self
    {
        $this->outputEnabled = $outputEnabled;
        return $this;
    }

    /**
     * @return bool
     */
    public function isOutputEnabled(): bool
    {
        return $this->outputEnabled;
    }

    /**
     * @return bool
     */
    public function collectErrors(): bool
    {
        return $this->collectErrors;
    }

    /**
     * @param bool $collectErrors
     *
     * @return GeneratorConfiguration
     */
    public function setCollectErrors(bool $collectErrors): self
    {
        $this->collectErrors = $collectErrors;
        return $this;
    }

    /**
     * @return string
     */
    public function getErrorRegistryClass(): string
    {
        return $this->errorRegistryClass;
    }

    /**
     * @param string $errorRegistryClass
     *
     * @return GeneratorConfiguration
     */
    public function setErrorRegistryClass(string $errorRegistryClass): self
    {
        $this->errorRegistryClass = $errorRegistryClass;
        return $this;
    }

    /**
     * @return string
     */
    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    /**
     * @param string $exceptionClass
     *
     * @return GeneratorConfiguration
     */
    public function setExceptionClass(string $exceptionClass): self
    {
        $this->exceptionClass = $exceptionClass;
        return $this;
    }
}
