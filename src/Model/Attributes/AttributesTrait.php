<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Attributes;

use PHPModelGenerator\Model\GeneratorConfiguration;

trait AttributesTrait
{
    /** @var PhpAttribute[] */
    private array $phpAttributes = [];

    public function addAttribute(
        PhpAttribute $attribute,
        ?GeneratorConfiguration $generatorConfiguration = null,
        ?int $enablementFlag = null,
    ): static {
        if ($generatorConfiguration
            && $enablementFlag
            && ($generatorConfiguration->getEnabledAttributes() & $enablementFlag) === 0
        ) {
            return $this;
        }

        $this->phpAttributes[] = $attribute;

        return $this;
    }

    /**
     * @return PhpAttribute[]
     */
    public function getAttributes(): array
    {
        return $this->phpAttributes;
    }
}
