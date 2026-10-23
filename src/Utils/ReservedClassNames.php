<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

/**
 * Words PHP does not accept as the name of a class declaration: the reserved keywords plus the
 * builtin type and special class names (`int`, `mixed`, `self`, ...). Contextual keywords such as
 * `enum` are valid class names and therefore deliberately absent. PHP resolves these
 * independent of case.
 */
final class ReservedClassNames
{
    private const array WORDS = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit',
        'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface',
        'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or',
        'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return',
        'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void',
        'while', 'xor', 'yield',
    ];

    public static function isReserved(string $className): bool
    {
        return in_array(strtolower($className), self::WORDS, true);
    }
}
