<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Attributes;

use PHPModelGenerator\Model\GeneratorConfiguration;

trait AttributesTrait
{
    /** @var PhpAttribute[] */
    protected array $phpAttributes = [];

    public function filterAttributes(callable $filter): static
    {
        $this->phpAttributes = array_values(array_filter($this->phpAttributes, $filter));

        return $this;
    }

    public function addAttribute(
        PhpAttribute $attribute,
        ?GeneratorConfiguration $generatorConfiguration = null,
        ?int $enablementFlag = null,
    ): static {
        if (
            $generatorConfiguration
            && $enablementFlag
            && ($generatorConfiguration->getEnabledAttributes() & $enablementFlag) === 0
        ) {
            return $this;
        }

        $this->phpAttributes[] = $attribute;

        return $this;
    }

    public function removeAttribute(string $attributeClassName): static
    {
        $this->phpAttributes = array_values(array_filter(
            $this->phpAttributes,
            static fn(PhpAttribute $attribute): bool => $attribute->getFqcn() !== $attributeClassName,
        ));

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
