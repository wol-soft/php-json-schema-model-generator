<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Templates;

use PHPModelGenerator\Exception\FileSystemException;

/**
 * Class Render
 *
 * @package PHPModelGenerator\Templates
 */
class Render
{
    /** @var array */
    private $templates = [];
    /** @var string */
    private $basePath = '';

    /**
     * Render constructor.
     *
     * @param string $basePath
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath;
    }

    /**
     * @param string $template
     * @param array  $variables
     *
     * @return string
     * @throws FileSystemException
     */
    public function renderTemplate(string $template, array $variables = []): string
    {
        $output = $this->getTemplate($template);

        foreach ($variables as $key => $value) {
            $output = str_replace("{{ $key }}", trim($value), $output);
        }

        return $output;
    }

    /**
     * Get the content for a template
     *
     * @param string $template
     *
     * @return string
     * @throws FileSystemException
     */
    protected function getTemplate(string $template) : string
    {
        if (!isset($this->templates[$template])) {
            $this->templates[$template] = file_get_contents($this->basePath . $template);

            if (!$this->templates[$template]) {
                unset($this->templates[$template]);
                throw new FileSystemException("Template $template not found");
            }
        }

        return $this->templates[$template];
    }
}
