<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ExtractedMethodValidator;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Wraps an input-space composition validator with a skip guard that bypasses the entire
 * composition check when the property value is already in the filter's output type-space.
 * Keeps filter-specific mechanics entirely within the filter package.
 */
final class FilterPreTransformGuardValidator extends ExtractedMethodValidator
{
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        private readonly AbstractComposedPropertyValidator $inner,
        private readonly string $skipCheck,
    ) {
        parent::__construct($generatorConfiguration, $property, '', [], '', []);
        $this->extractedMethodName = 'filterPreTransformGuard_' . $inner->getExtractedMethodName();
        $this->isResolved = true;
    }

    /**
     * Sets scope on both this guard and the wrapped inner validator, and registers the
     * inner validator's extracted method in the schema.
     *
     * RenderHelper::renderValidator() processes only validators that are directly in the
     * property's validators list. The inner wrapped validator is not on that list, so
     * RenderHelper never registers its extracted method automatically. The guard method's
     * generated code calls $this->innerMethodName by name, which must exist as a class
     * method. This block registers it manually; the hasMethod check prevents
     * double-registration when setScope() is called more than once on the same guard.
     *
     * $this->inner->setScope($schema) sets the inner validator's $scope field so that
     * its getCheck() can reference $this->scope during template rendering.
     */
    public function setScope(Schema $schema): void
    {
        parent::setScope($schema);
        $this->inner->setScope($schema);

        if (!$schema->hasMethod($this->inner->getExtractedMethodName())) {
            $schema->addMethod($this->inner->getExtractedMethodName(), $this->inner->getMethod());
        }
    }

    /**
     * Returns the wrapped input-space composition validator.
     */
    public function getInnerValidator(): AbstractComposedPropertyValidator
    {
        return $this->inner;
    }

    /**
     * Returns a method that short-circuits when the value is already in the filter's
     * output type-space, and otherwise delegates to the wrapped composition validator.
     */
    public function getMethod(): MethodInterface
    {
        return new class ($this) implements MethodInterface {
            public function __construct(private readonly FilterPreTransformGuardValidator $validator)
            {}

            public function getCode(): string
            {
                return $this->validator->renderGuardMethod();
            }
        };
    }

    /**
     * @throws RenderException
     */
    public function renderGuardMethod(): string
    {
        try {
            return $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'FilterPreTransformGuard.phptpl',
                [
                    'guardMethodName' => $this->getExtractedMethodName(),
                    'innerMethodName' => $this->inner->getExtractedMethodName(),
                    'skipCheck' => $this->skipCheck,
                    'viewHelper' => new RenderHelper($this->generatorConfiguration),
                ],
            );
        } catch (PHPMicroTemplateException $exception) {
            // @codeCoverageIgnoreStart
            throw new RenderException(
                "Can't render filter pre-transform guard {$this->getExtractedMethodName()}",
                0,
                $exception,
            );
            // @codeCoverageIgnoreEnd
        }
    }
}
