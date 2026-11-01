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
    private string $ownerName;
    private string $ownerMethodName;

    public function __construct(
        protected readonly GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        string $template,
        array $templateValues,
        string $exceptionClass,
        array $exceptionParams = [],
        ?Schema $schema = null,
    ) {
        $methodName = RenderHelper::extractedMethodNameCandidate(
            $property,
            str_replace('Validator', '', substr(strrchr(static::class, '\\'), 1)),
        );

        // Two validators of the same kind on the same attribute with identical schema JSON (two
        // compositions on one property, identical anyOf branches, ...) hash to the same name, and
        // a class registers each name once - the second validator would silently reuse the first
        // one's method body. The class-scoped reservation separates them deterministically.
        // Rejected: mixing spl_object_id() into the hash - it separates them too, but the id shifts
        // whenever an unrelated schema was processed earlier in the run, renaming the extracted
        // methods of classes that did not change.
        $this->extractedMethodName = $schema?->reserveMethodName($methodName) ?? $methodName;
        $this->ownerName = $property->getName();
        $this->ownerMethodName = $this->extractedMethodName;

        parent::__construct($property, $template, $templateValues, $exceptionClass, $exceptionParams);
    }

    /**
     * The exception rendered into the extracted method names the property the validator is bound to, so a validator
     * which is bound to a property with another name than the property it was created for (a recursive reference to
     * the definition owning the validator is a property of its own, named by the position of the reference) needs a
     * method of its own: a method shared by all of them would name the property which was rendered first for all of
     * them. The name derives from the name of the bound property only, so it is stable between generation runs and
     * all validators bound to properties with the same name share one method.
     *
     * Mixing the identity of the validator or of the property into the name was rejected: it shifts with unrelated
     * schemas processed earlier in the run, see the constructor.
     *
     * @inheritDoc
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface
    {
        /** @var static $clone */
        $clone = parent::withProperty($property);
        $clone->extractedMethodName = $property->getName() === $this->ownerName
            ? $this->ownerMethodName
            : $this->ownerMethodName . '_' . substr(md5($property->getName()), 0, 8);

        return $clone;
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

    /**
     * For a validator which derives its method from the method of another validator: keeps the clones bound to other
     * properties (see withProperty()) derived from the new name.
     */
    protected function renameExtractedMethod(string $methodName): void
    {
        $this->extractedMethodName = $methodName;
        $this->ownerMethodName = $methodName;
    }
}
