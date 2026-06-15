<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use Exception;
use InvalidArgumentException;
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
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\ImmutableMediaStringFilter;
use PHPModelGenerator\PropertyProcessor\Filter\MediaStringFilter;
use PHPModelGenerator\PropertyProcessor\Filter\NotEmptyFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\MediaString\ContentValidatorInterface;
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
    /** @var int */
    protected $enabledAttributes = PhpAttribute::JSON_POINTER
        | PhpAttribute::SCHEMA_NAME
        | PhpAttribute::REQUIRED
        | PhpAttribute::READ_WRITE_ONLY
        | PhpAttribute::DEPRECATED;

    /** @var DraftInterface | DraftFactoryInterface */
    protected $draft;

    /** @var ClassNameGeneratorInterface */
    protected $classNameGenerator;

    /** @var FilterInterface[] */
    protected $filter;
    /** @var FormatValidatorInterface[] */
    protected $formats;
    /** @var ContentValidatorInterface[] keyed by "mediaType|encoding" (empty string as null sentinel) */
    protected $contentValidators = [];

    /**
     * GeneratorConfiguration constructor.
     */
    public function __construct()
    {
        $this->draft = new AutoDetectionDraft();
        $this->classNameGenerator = new ClassNameGenerator();

        // add all built-in filter and format validators
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
     * Register a content validator for properties carrying contentMediaType and/or contentEncoding.
     *
     * Each parameter accepts:
     *   - null          — wildcard: matches any value (or absence) of that dimension
     *   - string        — matches exactly that media type or encoding
     *   - string[]      — matches any of the listed values for that dimension; must contain only
     *                     non-empty strings (null is not allowed inside an array)
     *
     * When arrays are provided the validator is registered once for every combination of
     * (mediaType × encoding) produced by the Cartesian product of the two lists.
     *
     * The lookup at generation time follows specificity order regardless of registration order:
     *   1. Exact match          ($mediaType, $encoding) — both non-null and matching
     *   2. Media-type wildcard  ($mediaType, null)      — any encoding
     *   3. Encoding wildcard    (null, $encoding)       — any media type
     *   4. Full wildcard        (null, null)            — matches everything
     *
     * @param null|string|string[] $mediaType
     * @param null|string|string[] $encoding
     *
     * @throws InvalidArgumentException if an array argument contains a non-string or empty string
     */
    public function addContentValidator(
        null|string|array $mediaType,
        null|string|array $encoding,
        ContentValidatorInterface $validator,
    ): self {
        $mediaTypes = $this->normalizeContentValidatorDimension($mediaType, 'mediaType');
        $encodings  = $this->normalizeContentValidatorDimension($encoding, 'encoding');

        foreach ($mediaTypes as $mediaTypeValue) {
            foreach ($encodings as $encodingValue) {
                $this->contentValidators[$this->buildContentValidatorKey($mediaTypeValue, $encodingValue)] = $validator;
            }
        }

        return $this;
    }

    /**
     * Normalise a mediaType/encoding argument into an array of nullable strings.
     * null  → [null]
     * string → [string]
     * array  → validated and returned as-is (each element must be a non-empty string)
     *
     * @return array<?string>
     * @throws InvalidArgumentException
     */
    private function normalizeContentValidatorDimension(null|string|array $value, string $dimensionName): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException(
                    "addContentValidator: every element of the \$$dimensionName array must be a non-empty string"
                );
            }
        }

        return $value;
    }

    public function getContentValidator(?string $mediaType, ?string $encoding): ?ContentValidatorInterface
    {
        $candidates = [
            $this->buildContentValidatorKey($mediaType, $encoding),
            $this->buildContentValidatorKey($mediaType, null),
            $this->buildContentValidatorKey(null, $encoding),
            $this->buildContentValidatorKey(null, null),
        ];

        foreach ($candidates as $key) {
            if (isset($this->contentValidators[$key])) {
                return $this->contentValidators[$key];
            }
        }

        return null;
    }

    private function buildContentValidatorKey(?string $mediaType, ?string $encoding): string
    {
        return ($mediaType ?? '') . '|' . ($encoding ?? '');
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
        return $this->classNameGenerator;
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
        return $this->draft;
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
            ->addFilter(new TrimFilter())
            ->addFilter(new MediaStringFilter())
            ->addFilter(new ImmutableMediaStringFilter());
    }

    public function getEnabledAttributes(): int
    {
        return $this->enabledAttributes;
    }

    public function setEnabledAttributes(int $enabledAttributes): self
    {
        $this->enabledAttributes = $enabledAttributes | PhpAttribute::ALWAYS_ENABLED_ATTRIBUTES;

        return $this;
    }

    public function enableAttributes(int $attributes): self
    {
        $this->enabledAttributes = $this->enabledAttributes | $attributes;

        return $this;
    }

    public function disableAttributes(int $attributes): self
    {
        $this->enabledAttributes = $this->enabledAttributes & ~$attributes | PhpAttribute::ALWAYS_ENABLED_ATTRIBUTES;

        return $this;
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
