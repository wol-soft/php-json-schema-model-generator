<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Element;

use PHPModelGenerator\Draft\Modifier\ModifierInterface;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Utils\TypeConverter;

class Type
{
    /** @var ModifierInterface[] */
    private array $modifiers = [];

    public function __construct(private readonly string $type, bool $typeCheck = true)
    {
        if ($typeCheck) {
            $this->modifiers[] = new TypeCheckModifier(TypeConverter::jsonSchemaToPHP($type));
        }
    }

    public function addModifier(ModifierInterface $modifier): self
    {
        $this->modifiers[] = $modifier;

        return $this;
    }

    public function addValidator(string $validatorKey, AbstractValidatorFactory $factory): self
    {
        $factory->setKey($validatorKey);
        $this->modifiers[$validatorKey] = $factory;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return ModifierInterface[]
     */
    public function getModifiers(): array
    {
        return $this->modifiers;
    }
}
