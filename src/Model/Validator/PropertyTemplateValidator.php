<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;

/**
 * Class PropertyTemplateValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyTemplateValidator extends AbstractPropertyValidator
{
    /** @var array */
    protected $templateValues;
    /** @var Schema|null */
    protected $scope;

    static private ?Render $renderer = null;

    /**
     * PropertyTemplateValidator constructor.
     */
    public function __construct(
        PropertyInterface $property,
        protected string $template,
        array $templateValues,
        string $exceptionClass,
        array $exceptionParams = [],
    ) {
        $this->templateValues = $templateValues;

        parent::__construct($property, $exceptionClass, $exceptionParams);
    }

    public function setScope(Schema $schema): void
    {
        $this->scope = $schema;

        if (isset($this->templateValues['schema'])) {
            $this->templateValues['schema'] = $schema;
        }

        $this->templateValues['isBaseValidator'] = in_array($this, $schema->getBaseValidators());
    }

    /**
     * Get the source code for the check to perform
     *
     * @throws RenderException
     */
    public function getCheck(): string
    {
        try {
            return $this->getRenderer()->renderTemplate(
                $this->template,
                // make sure the current bound property is available in the template
                $this->templateValues + ['property' => $this->property],
            );
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException("Can't render property validation template {$this->template}", 0, $exception);
        }
    }

    protected function getRenderer(): Render
    {
        if (!self::$renderer) {
            self::$renderer = new Render(
                join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'Templates']) . DIRECTORY_SEPARATOR,
            );
        }

        return self::$renderer;
    }
}
