<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Attributes;

use PHPModelGenerator\Model\GeneratorConfiguration;

trait AttributesTrait
{
    /**
     * @var PhpAttribute[]
     *
     * Protected visibility (not private) so that PropertyProxy::getAttributes() can
     * read the local attribute list and merge it with the underlying property's attrs.
     * No other subclass directly accesses this property; all mutations go through the
     * trait methods (addAttribute, removeAttribute, filterAttributes).
     */
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
