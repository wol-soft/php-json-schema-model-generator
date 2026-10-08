<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Renders the validator in a separate method. Might be required for recursive validations which would otherwise cause
 * infinite loops during validator rendering
 */
abstract class ExtractedMethodValidator extends PropertyTemplateValidator
{
    protected string $extractedMethodName;

    public function __construct(
        protected readonly GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        string $template,
        array $templateValues,
        string $exceptionClass,
        array $exceptionParams = [],
        ?Schema $schema = null,
    ) {
        $methodName = sprintf(
            '_validate%s_%s_%s',
            str_replace(' ', '', ucfirst($property->getAttribute())),
            str_replace('Validator', '', substr(strrchr(static::class, '\\'), 1)),
            md5(json_encode($property->getJsonSchema()->getJson())),
        );

        // Two validators of the same kind on the same attribute with identical schema JSON (two
        // compositions on one property, identical anyOf branches, ...) hash to the same name, and
        // a class registers each name once - the second validator would silently reuse the first
        // one's method body. The class-scoped reservation separates them deterministically.
        // Rejected: mixing spl_object_id() into the hash - it separates them too, but the id shifts
        // whenever an unrelated schema was processed earlier in the run, renaming the extracted
        // methods of classes that did not change.
        $this->extractedMethodName = $schema?->reserveMethodName($methodName) ?? $methodName;

        parent::__construct($property, $template, $templateValues, $exceptionClass, $exceptionParams);
    }

    public function getMethod(): MethodInterface
    {
        return new class ($this, $this->generatorConfiguration) implements MethodInterface {
            public function __construct(
                private readonly ExtractedMethodValidator $validator,
                private readonly GeneratorConfiguration $generatorConfiguration,
            ) {}

            public function getCode(): string
            {
                return $this->validator->renderExtractedMethod($this->generatorConfiguration);
            }
        };
    }

    /**
     * @throws RenderException
     */
    public function renderExtractedMethod(GeneratorConfiguration $generatorConfiguration): string
    {
        $renderHelper = new RenderHelper($generatorConfiguration);

        try {
            return $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ExtractedMethod.phptpl',
                [
                    'methodName' => $this->getExtractedMethodName(),
                    'setUp' => $this->getValidatorSetUp(),
                    'check' => $this->getCheck(),
                    'errorHandling' => $renderHelper->validationError($this),
                    'viewHelper' => $renderHelper,
                ],
            );
        } catch (PHPMicroTemplateException $exception) {
            // @codeCoverageIgnoreStart
            throw new RenderException(
                "Can't render extracted method {$this->getExtractedMethodName()}",
                0,
                $exception,
            );
            // @codeCoverageIgnoreEnd
        }
    }

    public function getExtractedMethodName(): string
    {
        return $this->extractedMethodName;
    }
}
