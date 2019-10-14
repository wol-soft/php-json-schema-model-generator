<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\RenderException;

/**
 * Class PropertyTemplateValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyTemplateValidator extends AbstractPropertyValidator
{
    /** @var string */
    protected $template;
    /** @var array */
    protected $templateValues;
    /** @var Render */
    static private $renderer;

    /**
     * PropertyValidator constructor.
     *
     * @param string $exceptionMessage
     * @param string $template
     * @param array  $templateValues
     */
    public function __construct(
        string $exceptionMessage,
        string $template,
        array $templateValues
    ) {
        $this->exceptionMessage = $exceptionMessage;
        $this->template = $template;
        $this->templateValues = $templateValues;
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
            return $this->getRenderer()->renderTemplate($this->template, $this->templateValues);
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException("Can't render property validation template {$this->template}", 0, $exception);
        }
    }

    /**
     * @return Render
     */
    protected function getRenderer(): Render
    {
        if (!self::$renderer) {
            self::$renderer = new Render(
                join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'Templates']) . DIRECTORY_SEPARATOR
            );
        }

        return self::$renderer;
    }
}
