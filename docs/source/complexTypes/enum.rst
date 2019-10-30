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

Generated interface (no typehints are generated as it's an untyped enum):

.. code-block:: php

    public function setExample($example): self;
    public function getExample();

Possible exceptions:

* Invalid value for example declined by enum constraint
