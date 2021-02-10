Enum
====

Enums can be used to define a set of constant values a property must accept.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "enum": ["ABC", "DEF"]
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(?string $example): self;
    public function getExample(): ?string;

Possible exceptions:

* Invalid type for example. Requires string, got __TYPE__
* Invalid value for example declined by enum constraint

Untyped Enum
------------

An enum can also be defined without a specific type.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "enum": ["ABC", 10, true, null]
            }
        }
    }

Generated interface (no typehints are generated as it's a mixed untyped enum. If all values in the untyped enum are of the same type [eg. only strings] the generated interface will contain type hinting):

.. code-block:: php

    public function setExample($example): self;
    public function getExample();

Possible exceptions:

* Invalid value for example declined by enum constraint

The thrown exception will be an *PHPModelGenerator\\Exception\\Generic\\EnumException* which provides the following methods to get further error details:

.. code-block:: php

    // get all values which are allowed by the enum
    public function getAllowedValues(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
