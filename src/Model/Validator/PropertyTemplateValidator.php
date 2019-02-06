<?php

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\RenderException;

/**
 * Class CallbackPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyTemplateValidator extends AbstractPropertyValidator implements PropertyValidatorInterface
{
    /** @var string */
    protected $template;
    /** @var array */
    protected $templateValues;
    /** @var Render */
    static protected $renderer;

    /**
     * PropertyValidator constructor.
     *
     * @param string $exceptionClass
     * @param string $exceptionMessage
     * @param string $template
     * @param array  $templateValues
     */
    public function __construct(
        string $exceptionClass,
        string $exceptionMessage,
        string $template,
        array $templateValues
    ) {
        $this->exceptionClass = $exceptionClass;
        $this->exceptionMessage = $exceptionMessage;
        $this->template = $template;
        $this->templateValues = $templateValues;

        if (!static::$renderer) {
            static::$renderer = new Render(
                join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'Templates']) . DIRECTORY_SEPARATOR
            );
        }
    }

    /**
     * Get the source code for the check to perform
     *
     * @return string
     *
     * @throws RenderException
     */
    public function getCheck(): string
    {
        try {
            return static::$renderer->renderTemplate($this->template, $this->templateValues);
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException(
                "Can't render property validation template {$this->template} with values " .
                    print_r($this->templateValues, true),
                0,
                $exception
            );
        }
    }
}
