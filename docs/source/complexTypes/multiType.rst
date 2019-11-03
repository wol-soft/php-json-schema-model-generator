Multi Type
==========

By providing an array with types for a property multiple types can be allowed.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": ["number", "string"]
            }
        }
    }

Generated interface (doesn't contain type hints as multiple types are allowed):

.. code-block:: php

    public function setExample($example): self;
    public function getExample();

Possible exceptions:

* Invalid type for property. Requires [float, string], got __TYPE__

Additional validators
---------------------

For each type given in the allowed types array additional validators may be added to the property:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": ["number", "string", "array"],
                "minimum": 10,
                "minLength": 4,
                "items": {
                    "type": "string"
                },
                "minItems": 2
            }
        }
    }

The validators are applied if the given input matches the corresponding type.
For example if an array **["Hello", 123, "Goodbye"]** is given the validation will fail as numbers aren't allowed in arrays:

.. code-block:: none

    Invalid item in array example:
      - invalid item #1
        * Invalid type for item of array example. Requires string, got integer