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

    // $example will be type-annotated with `float|string`
    public function setExample($example): self;
    // $example will be type-annotated with `float|string|null` (as the property isn't required)
    public function getExample();

Possible exceptions:

* Invalid type for property. Requires [float, string], got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

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

The property example will be type hinted with `float|string|string[]|null`.
The validators are applied if the given input matches the corresponding type.
For example if an array **["Hello", 123, "Goodbye"]** is given the validation will fail as numbers aren't allowed in arrays:

.. code-block:: none

    Invalid items in array example:
      - invalid item #1
        * Invalid type for item of array example. Requires string, got integer