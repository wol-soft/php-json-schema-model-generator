<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\NotEmptyFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TransformingFilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Utils\ClassNameGenerator;
use PHPModelGenerator\Utils\ClassNameGeneratorInterface;
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
    protected $allowImplicitNull = true;
    /** @var bool */
    protected $prettyPrint = false;
    /** @var bool */
    protected $outputEnabled = true;
    /** @var bool */
    protected $collectErrors = true;
    /** @var string */
    protected $errorRegistryClass = ErrorRegistryException::class;
    /** @var string */
    protected $exceptionClass = ValidationException::class;
    /** @var bool */
    protected $serialization = false;
    /** @var ClassNameGeneratorInterface */
    protected $classNameGenerator;
    /** @var FilterInterface[] */
    protected $filter;

    /**
     * GeneratorConfiguration constructor.
     */
    public function __construct()
    {
        $this->classNameGenerator = new ClassNameGenerator();

        $this
            ->addFilter(new DateTimeFilter())
            ->addFilter(new NotEmptyFilter())
            ->addFilter(new TrimFilter());
    }

    /**
     * Add an additional filter
     *
     * @param FilterInterface $filter
     *
     * @return $this
     *
     * @throws InvalidFilterException
     */
    public function addFilter(FilterInterface $filter): self
    {
        if (!(count($filter->getFilter()) === 2) ||
            !is_string($filter->getFilter()[0]) ||
            !is_string($filter->getFilter()[1]) ||
            !is_callable($filter->getFilter())
        ) {
            throw new InvalidFilterException("Invalid filter callback for filter {$filter->getToken()}");
        }

        if ($filter instanceof TransformingFilterInterface) {
            if (!(count($filter->getSerializer()) === 2) ||
                !is_string($filter->getSerializer()[0]) ||
                !is_string($filter->getSerializer()[1]) ||
                !is_callable($filter->getSerializer())
            ) {
                throw new InvalidFilterException("Invalid serializer callback for filter {$filter->getToken()}");
            }
        }

        foreach ($filter->getAcceptedTypes() as $acceptedType) {
            if (!in_array($acceptedType, ['integer', 'number', 'boolean', 'string', 'array']) &&
                !class_exists($acceptedType)
            ) {
                throw new InvalidFilterException('Filter accepts invalid types');
            }
        }

        $this->filter[$filter->getToken()] = $filter;

        return $this;
    }

    /**
     * Get a filter by the given token
     *
     * @param string $token
     *
     * @return FilterInterface|null
     */
    public function getFilter(string $token): ?FilterInterface
    {
        return $this->filter[$token] ?? null;
    }

    /**
     * @return ClassNameGeneratorInterface
     */
    public function getClassNameGenerator(): ClassNameGeneratorInterface
    {
        return $this->classNameGenerator;
    }

    /**
     * @param ClassNameGeneratorInterface $classNameGenerator
     */
    public function setClassNameGenerator(ClassNameGeneratorInterface $classNameGenerator): void
    {
        $this->classNameGenerator = $classNameGenerator;
    }

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
     * @return bool
     */
    public function hasSerializationEnabled(): bool
    {
        return $this->serialization;
    }

    /**
     * @param bool $serialization
     *
     * @return $this
     */
    public function setSerialization(bool $serialization): self
    {
        $this->serialization = $serialization;

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

    /**
     * @return bool
     */
    public function isImplicitNullAllowed(): bool
    {
        return $this->allowImplicitNull;
    }

    /**
     * @param bool $allowImplicitNull
     *
     * @return GeneratorConfiguration
     */
    public function setImplicitNull(bool $allowImplicitNull): self
    {
        $this->allowImplicitNull = $allowImplicitNull;

        return $this;
    }
}
