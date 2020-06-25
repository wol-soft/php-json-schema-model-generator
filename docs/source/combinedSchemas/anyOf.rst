Any Of
======

The `anyOf` keyword can be used to combine multiple subschemas. The provided value must be valid against at least one of the subschemas.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "anyOf": [
                    {
                        "type": "number",
                        "multipleOf": 5
                    },
                    {
                        "type": "number",
                        "multipleOf": 3
                    }
                ]
            }
        }
    }

Valid values are eg. 3, 5, 6, 9, 10, 12, 15. Invalid values are eg. 1, 2, 4, 7, 8, 11 or any non numeric values.

Generated interface:

.. code-block:: php

    public function setExample(float $example): self;
    public function getExample(): float;


Possible exception (if a string is provided):

.. code-block:: none

    Invalid value for example declined by composition constraint.
      Requires to match at least one composition element.
      - Composition element #1: Failed
        * Invalid type for example. Requires float, got string
      - Composition element #2: Failed
        * Invalid type for example. Requires float, got string

The thrown exception will be a *PHPModelGenerator\\Exception\\ComposedValue\\AnyOfException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by composition elements
    public function getCompositionErrorCollection(): array
    // get the amount of succeeded composition elements
    public function getSucceededCompositionElements(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

.. hint::

    When combining multiple nested objects with an `anyOf` composition a `merged property <mergedProperty.html>`__ will be generated
