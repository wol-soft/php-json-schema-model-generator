<?php

declare(strict_types=1);

namespace PHPModelGenerator\Exception;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\Json\JsonPointerLocator;
use PHPModelGenerator\Utils\Json\JsonSourcePosition;
use PHPModelGenerator\Utils\Json\JsonSyntaxErrorLocator;
use Throwable;

class SchemaException extends PHPModelGeneratorException
{
    private ?string $schemaFile = null;
    private ?int $sourceLine = null;
    private ?int $sourceColumn = null;

    /**
     * When $jsonSchema is provided, getSchemaFile() is populated from it regardless of raw source availability.
     * getSourceLine()/getSourceColumn() (and the "at line X, column Y" suffix on the message) additionally require
     * $jsonSchema to carry raw source text and its pointer to resolve against that text; when either is missing,
     * those two accessors stay null and the message is left unchanged - resolution never throws.
     */
    public function __construct(
        string $message,
        ?JsonSchema $jsonSchema = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        $position = null;

        if ($jsonSchema !== null) {
            $this->schemaFile = $jsonSchema->getFile();
            $rawSource = $jsonSchema->getRawSource();

            if ($rawSource !== null) {
                $position = JsonPointerLocator::locate($rawSource, $jsonSchema->getPointer());
            }
        }

        parent::__construct(self::appendLocation($message, $position), $code, $previous);

        $this->sourceLine = $position?->line;
        $this->sourceColumn = $position?->column;
    }

    /**
     * Named constructor for the "the schema file isn't valid JSON at all" case, where json_last_error() gives no
     * position of its own - $rawSource is scanned with JsonSyntaxErrorLocator to find one.
     */
    public static function invalidJson(string $file, string $rawSource): self
    {
        $position = JsonSyntaxErrorLocator::locate($rawSource);

        $exception = new self(self::appendLocation("Invalid JSON-Schema file $file", $position));
        $exception->schemaFile = $file;
        $exception->sourceLine = $position?->line;
        $exception->sourceColumn = $position?->column;

        return $exception;
    }

    /**
     * Falls back to the wrapped previous exception's location when this exception carries none of its own - a
     * generic wrapper (e.g. "Unresolved Reference ..." around a caught exception) must not hide the more specific
     * location a nested SchemaException already resolved.
     */
    public function getSchemaFile(): ?string
    {
        return $this->schemaFile ?? $this->previousSchemaException()?->getSchemaFile();
    }

    public function getSourceLine(): ?int
    {
        return $this->sourceLine ?? $this->previousSchemaException()?->getSourceLine();
    }

    public function getSourceColumn(): ?int
    {
        return $this->sourceColumn ?? $this->previousSchemaException()?->getSourceColumn();
    }

    private function previousSchemaException(): ?self
    {
        return $this->getPrevious() instanceof self ? $this->getPrevious() : null;
    }

    private static function appendLocation(string $message, ?JsonSourcePosition $position): string
    {
        return $position === null ? $message : "$message at line {$position->line}, column {$position->column}";
    }
}
