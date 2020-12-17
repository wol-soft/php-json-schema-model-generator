<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\Property\PropertyInterface;

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
     * PropertyTemplateValidator constructor.
     *
     * @param PropertyInterface $property
     * @param string $template
     * @param array $templateValues
     * @param string $exceptionClass
     * @param array $exceptionParams
     */
    public function __construct(
        PropertyInterface $property,
        string $template,
        array $templateValues,
        string $exceptionClass,
        array $exceptionParams = []
    ) {
        $this->template = $template;
        $this->templateValues = $templateValues;

        parent::__construct($property, $exceptionClass, $exceptionParams);
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
            return $this->getRenderer()->renderTemplate(
                $this->template,
                // make sure the current bound property is available in the template
                $this->templateValues + ['property' => $this->property]
            );
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
