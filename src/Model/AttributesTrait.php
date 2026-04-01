<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

trait AttributesTrait
{
    /** @var PhpAttribute[] */
    private array $phpAttributes = [];

    public function addAttribute(PhpAttribute $attribute): static
    {
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
