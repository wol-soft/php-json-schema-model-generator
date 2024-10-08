<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Renders the validator in a separate method. Might be required for recursive validations which would otherwise cause
 * infinite loops during validator rendering
 */
abstract class ExtractedMethodValidator extends PropertyTemplateValidator
{
    private string $extractedMethodName;

    public function __construct(
        private GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        string $template,
        array $templateValues,
        string $exceptionClass,
        array $exceptionParams = [],
    ) {
        $this->extractedMethodName = sprintf(
            'validate%s_%s_%s',
            str_replace(' ', '', ucfirst($property->getAttribute())),
            str_replace('Validator', '', substr(strrchr(static::class, '\\'), 1)),
            uniqid(),
        );

        parent::__construct($property, $template, $templateValues, $exceptionClass, $exceptionParams);
    }

    public function getMethod(): MethodInterface
    {
        return new class ($this, $this->generatorConfiguration) implements MethodInterface {
            public function __construct(
                private ExtractedMethodValidator $validator,
                private GeneratorConfiguration $generatorConfiguration,
            ) {}

            public function getCode(): string
            {
                $renderHelper = new RenderHelper($this->generatorConfiguration);
                return "private function {$this->validator->getExtractedMethodName()}(&\$value, \$modelData): void {
                    {$this->validator->getValidatorSetUp()}
                    
                    if ({$this->validator->getCheck()}) {
                        {$renderHelper->validationError($this->validator)}
                    }
                }";
            }
        };
    }

    public function getExtractedMethodName(): string
    {
        return $this->extractedMethodName;
    }
}
