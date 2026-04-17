<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use Exception;
use PHPModelGenerator\Draft\AutoDetectionDraft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Draft\DraftInterface;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Format\FormatValidatorInterface;
use PHPModelGenerator\Format\IriFormatValidator;
use PHPModelGenerator\Format\IriReferenceFormatValidator;
use PHPModelGenerator\Format\Ipv6FormatValidator;
use PHPModelGenerator\Format\RegexFormatValidator;
use PHPModelGenerator\Format\UriFormatValidator;
use PHPModelGenerator\Format\UriReferenceFormatValidator;
use PHPModelGenerator\Format\UriTemplateFormatValidator;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\NotEmptyFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Utils\ClassNameGenerator;
use PHPModelGenerator\Utils\ClassNameGeneratorInterface;

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
    protected $defaultArraysToEmptyArray = false;
    /** @var bool */
    protected $denyAdditionalProperties = false;
    /** @var bool */
    protected $outputEnabled = true;
    /** @var bool */
    protected $collectErrors = true;
    /** @var string */
    protected $errorRegistryClass = ErrorRegistryException::class;
    /** @var bool */
    protected $serialization = false;

    /** @var DraftInterface | DraftFactoryInterface */
    protected $draft;

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
        $this->initFilter();
        $this->initFormatValidator();
    }

    /**
     * Add an additional filter
     *
     * @throws Exception
     * @throws InvalidFilterException
     */
    public function addFilter(FilterInterface ...$additionalFilter): self
    {
        foreach ($additionalFilter as $filter) {
            $this->validateFilterCallback(
                $filter->getFilter(),
                "Invalid filter callback for filter {$filter->getToken()}",
            );

            if ($filter instanceof TransformingFilterInterface) {
                $this->validateFilterCallback(
                    $filter->getSerializer(),
                    "Invalid serializer callback for filter {$filter->getToken()}"
                );
            }

            foreach ($filter->getAcceptedTypes() as $acceptedType) {
                if (
                    !in_array($acceptedType, ['integer', 'number', 'boolean', 'string', 'array', 'null']) &&
                    !class_exists($acceptedType)
                ) {
                    throw new InvalidFilterException('Filter accepts invalid types');
                }
            }

            $this->filter[$filter->getToken()] = $filter;
        }

        return $this;
    }

    /**
     * Add an additional format
     */
    public function addFormat(string $formatKey, FormatValidatorInterface $format): self
    {
        $this->formats[$formatKey] = $format;

        return $this;
    }

    public function getFormat(string $formatKey): ?FormatValidatorInterface
    {
        return $this->formats[$formatKey] ?? null;
    }

    /**
     * @throws InvalidFilterException
     */
    private function validateFilterCallback(array $callback, string $message): void
    {
        if (
            !(count($callback) === 2) ||
            !is_string($callback[0]) ||
            !is_string($callback[1]) ||
            !is_callable($callback)
        ) {
            throw new InvalidFilterException($message);
        }
    }

    /**
     * Get a filter by the given token
     */
    public function getFilter(string $token): ?FilterInterface
    {
        return $this->filter[$token] ?? null;
    }

    public function getClassNameGenerator(): ClassNameGeneratorInterface
    {
        return $this->classNameGenerator ??= new ClassNameGenerator();
    }

    public function setClassNameGenerator(ClassNameGeneratorInterface $classNameGenerator): self
    {
        $this->classNameGenerator = $classNameGenerator;

        return $this;
    }

    public function getNamespacePrefix(): string
    {
        return $this->namespacePrefix;
    }

    public function setNamespacePrefix(string $namespacePrefix): self
    {
        $this->namespacePrefix = trim($namespacePrefix, '\\');

        return $this;
    }

    public function isDefaultArraysToEmptyArrayEnabled(): bool
    {
        return $this->defaultArraysToEmptyArray;
    }

    public function setDefaultArraysToEmptyArray(bool $defaultArraysToEmptyArray): self
    {
        $this->defaultArraysToEmptyArray = $defaultArraysToEmptyArray;

        return $this;
    }

    public function isImmutable(): bool
    {
        return $this->immutable;
    }

    public function setImmutable(bool $immutable): self
    {
        $this->immutable = $immutable;

        return $this;
    }

    public function denyAdditionalProperties(): bool
    {
        return $this->denyAdditionalProperties;
    }

    public function setDenyAdditionalProperties(bool $denyAdditionalProperties): self
    {
        $this->denyAdditionalProperties = $denyAdditionalProperties;

        return $this;
    }

    public function hasSerializationEnabled(): bool
    {
        return $this->serialization;
    }

    public function setSerialization(bool $serialization): self
    {
        $this->serialization = $serialization;

        return $this;
    }

    public function setOutputEnabled(bool $outputEnabled): self
    {
        $this->outputEnabled = $outputEnabled;

        return $this;
    }

    public function isOutputEnabled(): bool
    {
        return $this->outputEnabled;
    }

    public function collectErrors(): bool
    {
        return $this->collectErrors;
    }

    public function setCollectErrors(bool $collectErrors): self
    {
        $this->collectErrors = $collectErrors;

        return $this;
    }

    public function getErrorRegistryClass(): string
    {
        return $this->errorRegistryClass;
    }

    public function setErrorRegistryClass(string $errorRegistryClass): self
    {
        $this->errorRegistryClass = $errorRegistryClass;

        return $this;
    }

    public function getDraft(): DraftInterface | DraftFactoryInterface
    {
        return $this->draft ??= new AutoDetectionDraft();
    }

    public function setDraft(DraftInterface | DraftFactoryInterface $draft): self
    {
        $this->draft = $draft;

        return $this;
    }

    public function isImplicitNullAllowed(): bool
    {
        return $this->allowImplicitNull;
    }

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
        // RFC 3339 date-time: YYYY-MM-DDTHH:MM:SS with optional fractional seconds and timezone
        $this->addFormat(
            'date-time',
            new FormatValidatorFromRegEx(
                '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+\-]\d{2}:\d{2})$/',
            ),
        );

        // RFC 3339 full-date: YYYY-MM-DD
        $this->addFormat(
            'date',
            new FormatValidatorFromRegEx('/^\d{4}-\d{2}-\d{2}$/'),
        );

        // RFC 3339 full-time: HH:MM:SS with optional fractional seconds and timezone
        $this->addFormat(
            'time',
            new FormatValidatorFromRegEx(
                '/^\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+\-]\d{2}:\d{2})$/',
            ),
        );

        // RFC 5322 email address (simplified)
        $this->addFormat(
            'email',
            new FormatValidatorFromRegEx('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/'),
        );

        // idn-email: internationalized email — same simplified check allowing unicode
        $this->addFormat(
            'idn-email',
            new FormatValidatorFromRegEx('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u'),
        );

        // RFC 1123 hostname
        $hostnamePattern = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*'
            . '[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/';
        $this->addFormat('hostname', new FormatValidatorFromRegEx($hostnamePattern));

        // idn-hostname: internationalized hostname — allow unicode labels
        $idnHostnamePattern = '/^(?:[a-zA-Z0-9\pL](?:[a-zA-Z0-9\pL\-]{0,61}[a-zA-Z0-9\pL])?\.)*'
            . '[a-zA-Z0-9\pL](?:[a-zA-Z0-9\pL\-]{0,61}[a-zA-Z0-9\pL])?$/u';
        $this->addFormat('idn-hostname', new FormatValidatorFromRegEx($idnHostnamePattern));

        // IPv4 address
        $this->addFormat(
            'ipv4',
            new FormatValidatorFromRegEx(
                '/^(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/',
            ),
        );

        // RFC 6901 JSON Pointer
        $this->addFormat(
            'json-pointer',
            new FormatValidatorFromRegEx('/^(\/([^~]|~[01])*)*$/'),
        );

        // JSON Schema relative JSON pointer: optional integer prefix + JSON pointer
        $this->addFormat(
            'relative-json-pointer',
            new FormatValidatorFromRegEx('/^\d+(\/([^~]|~[01])*)*$|^\d+#$/'),
        );

        // Class-based validators from the production package
        $this->addFormat('ipv6', new Ipv6FormatValidator());
        $this->addFormat('uri', new UriFormatValidator());
        $this->addFormat('uri-reference', new UriReferenceFormatValidator());
        $this->addFormat('uri-template', new UriTemplateFormatValidator());
        $this->addFormat('iri', new IriFormatValidator());
        $this->addFormat('iri-reference', new IriReferenceFormatValidator());
        $this->addFormat('regex', new RegexFormatValidator());
    }
}
