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

        $output = preg_replace_callback(
            '/\{% foreach (?P<variable>.+) as (?P<scopeVar>.+) %\}(?P<body>.+)\{% endforeach %\}/s',
            function (array $matches) use ($variables): string {

                $output = '';
                foreach ($variables[$matches['variable']] as $value) {
                    $output .= $this->replaceVariablesInTemplate(
                        $matches['body'],
                        array_merge($variables, [$matches['scopeVar'] =>$value]));
                }
                return $output;
            },
            $output
        );

        return $this->replaceVariablesInTemplate($output, $variables);
    }

    /**
     * Replace variables in a given template section and execute function calls
     *
     * @param string $template
     * @param array  $variables
     *
     * @return string
     */
    protected function replaceVariablesInTemplate(string $template, array $variables) : string
    {
        $template = preg_replace_callback(
            '/\{\{ (?P<object>[a-z]+)\.(?P<method>[a-z]+)\(\) \}\}/i',
            function (array $matches) use ($variables): string {
                if (!isset($variables[$matches['object']]) ||
                    !is_callable([$variables[$matches['object']], $matches['method']])
                ) {
                    echo 'Function not callable';
                }

                return $variables[$matches['object']]->{$matches['method']}();
            },
            $template
        );

        foreach ($variables as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $template = str_replace("{{ $key }}", trim($value), $template);
        }

        return $template;
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
