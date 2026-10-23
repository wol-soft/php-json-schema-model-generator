<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * How the result of a validator of an object may change - in either direction, an update may also turn an invalid
 * result valid (e.g. adding a key satisfies minProperties) - with an update (setter, populate, additional property
 * accessors) of a key of the object. Ordered by strength: None < AddedKey < Value.
 */
enum ObjectUpdateDependency
{
    /** An update of the key can't change the result */
    case None;
    /** Adding the key may change the result, changing the value of a present key can't */
    case AddedKey;
    /** Any update of the key may change the result */
    case Value;

    /**
     * The dependency of the validator on an update of the key, or on an update of any key the object doesn't declare
     * as property if no key is given. A validator which doesn't describe its dependency depends on the value of every
     * key.
     */
    public static function ofValidator(PropertyValidatorInterface $validator, ?string $key): self
    {
        if (!$validator instanceof ObjectUpdateDependentValidatorInterface) {
            return self::Value;
        }

        return $key === null ? $validator->getUndeclaredKeyDependency() : $validator->getObjectUpdateDependency($key);
    }

    /**
     * The strongest dependency of the validators
     *
     * @param PropertyValidatorInterface[] $validators
     */
    public static function ofValidators(array $validators, ?string $key): self
    {
        $dependency = self::None;

        foreach ($validators as $validator) {
            $dependency = $dependency->strongest(self::ofValidator($validator, $key));
        }

        return $dependency;
    }

    public function strongest(self $other): self
    {
        return $this->getStrength() >= $other->getStrength() ? $this : $other;
    }

    /**
     * Whether an update of a key with this dependency must be validated: adding the key requires any dependency,
     * changing the value of a present key requires a dependency on the value.
     */
    public function requiresValidation(bool $keyAdded): bool
    {
        return $keyAdded ? $this !== self::None : $this === self::Value;
    }

    private function getStrength(): int
    {
        return match ($this) {
            self::None => 0,
            self::AddedKey => 1,
            self::Value => 2,
        };
    }
}
