<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property\Serializer;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TransformingFilterInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class TransformingFilterSerializer
 *
 * @package PHPModelGenerator\Model\Property\Serializer
 */
class TransformingFilterSerializer implements MethodInterface
{
    /** @var string */
    protected $propertyName;
    /** @var TransformingFilterInterface */
    protected $filter;
    /** @var array */
    private $filterOptions;
    /** @var GeneratorConfiguration */
    private $generatorConfiguration;

    /**
     * TransformingFilterSerializer constructor.
     *
     * @param string $propertyName
     * @param TransformingFilterInterface $filter
     * @param array $filterOptions
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function __construct(
        string $propertyName,
        TransformingFilterInterface $filter,
        array $filterOptions,
        GeneratorConfiguration $generatorConfiguration
    ) {
        $this->propertyName = $propertyName;
        $this->filter = $filter;
        $this->filterOptions = $filterOptions;
        $this->generatorConfiguration = $generatorConfiguration;
    }

    /**
     * @return string
     *
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function getCode(): string
    {
        return (new Render(join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', '..', 'Templates']) . DIRECTORY_SEPARATOR))
            ->renderTemplate(
                DIRECTORY_SEPARATOR . 'Serializer' . DIRECTORY_SEPARATOR . 'TransformingFilterSerializer.phptpl',
                [
                    'viewHelper' => new RenderHelper($this->generatorConfiguration),
                    'property' => $this->propertyName,
                    'serializerClass' => $this->filter->getSerializer()[0],
                    'serializerMethod' => $this->filter->getSerializer()[1],
                    'serializerOptions' => var_export($this->filterOptions, true),
                ]
            );
    }
}
