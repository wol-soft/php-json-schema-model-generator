All Of
======

The `allOf` keyword can be used to combine multiple subschemas. The provided value must be valid against each of the subschemas.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "allOf": [
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

Valid values are eg. 15, 30, 45. Invalid values are eg. 1, 2, 3, 4, 5 or any non numeric values.

Generated interface:

.. code-block:: php

    public function setExample(float $example): self;
    public function getExample(): float;


Possible exception (if a string is provided):

.. code-block:: none

    Invalid value for example declined by composition constraint.
      Requires to match 2 composition elements but matched 0 elements.
      - Composition element #1: Failed
        * Invalid type for example. Requires float, got string
      - Composition element #2: Failed
        * Invalid type for example. Requires float, got string

Possible exception (if eg. 5 is provided, which matches only one subschema):

.. code-block:: none

    Invalid value for example declined by composition constraint.
      Requires to match one composition element but matched 2 elements.
      - Composition element #1: Valid
      - Composition element #2: Failed
        * Value for example must be a multiple of 3

.. hint::

    When combining multiple nested objects with an `allOf` composition a `merged property <mergedProperty.html>`__ will be generated
