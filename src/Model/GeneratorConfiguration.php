<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Format\FormatValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\NotEmptyFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Utils\ClassNameGenerator;
use PHPModelGenerator\Utils\ClassNameGeneratorInterface;
use PHPModelGenerator\Exception\ErrorRegistryException;

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
    protected $immutable = true;
    /** @var bool */
    protected $allowImplicitNull = false;
    /** @var bool */
    protected $prettyPrint = false;
    /** @var bool */
    protected $outputEnabled = true;
    /** @var bool */
    protected $collectErrors = true;
    /** @var string */
    protected $errorRegistryClass = ErrorRegistryException::class;
    /** @var bool */
    protected $serialization = false;

    /** @var ClassNameGeneratorInterface */
    protected $classNameGenerator;

    /** @var FilterInterface[] */
    protected $filter;
    /** @var FormatValidatorInterface[] */
    protected $formats;

    /**
     * GeneratorConfiguration constructor.
     */
    public function __construct()
    {
        $this->classNameGenerator = new ClassNameGenerator();

        // add all built-in filter and format validators
        $this->initFilter();
        $this->initFormatValidator();
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
        $this->validateFilterCallback($filter->getFilter(), "Invalid filter callback for filter {$filter->getToken()}");

        if ($filter instanceof TransformingFilterInterface) {
            $this->validateFilterCallback(
                $filter->getSerializer(),
                "Invalid serializer callback for filter {$filter->getToken()}"
            );
        }

        foreach ($filter->getAcceptedTypes() as $acceptedType) {
            if (!in_array($acceptedType, ['integer', 'number', 'boolean', 'string', 'array', 'null']) &&
                !class_exists($acceptedType)
            ) {
                throw new InvalidFilterException('Filter accepts invalid types');
            }
        }

        $this->filter[$filter->getToken()] = $filter;

        return $this;
    }

    /**
     * Add an additional format
     *
     * @param string $formatKey
     * @param FormatValidatorInterface $format
     *
     * @return $this
     */
    public function addFormat(string $formatKey, FormatValidatorInterface $format): self
    {
        $this->formats[$formatKey] = $format;

        return $this;
    }

    /**
     * @param string $formatKey
     *
     * @return FormatValidatorInterface|null
     */
    public function getFormat(string $formatKey): ?FormatValidatorInterface
    {
        return $this->formats[$formatKey] ?? null;
    }

    /**
     * @param array $callback
     * @param string $message
     *
     * @throws InvalidFilterException
     */
    private function validateFilterCallback(array $callback, string $message): void
    {
        if (!(count($callback) === 2) ||
            !is_string($callback[0]) ||
            !is_string($callback[1]) ||
            !is_callable($callback)
        ) {
            throw new InvalidFilterException($message);
        }
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
        $this->namespacePrefix = trim($namespacePrefix, '\\');

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

    private function initFilter(): void
    {
        $this
            ->addFilter(new DateTimeFilter())
            ->addFilter(new NotEmptyFilter())
            ->addFilter(new TrimFilter());
    }

    private function initFormatValidator(): void
    {
        $this
            ->addFormat('hostname', new FormatValidatorFromRegEx('/^(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9])$/i'));
    }
}
