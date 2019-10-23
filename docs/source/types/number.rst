Numeric types
=============

Used for properties containing numeric values. Properties with the type `integer` are converted to the PHP type `int`, Properties with the type `number` to the type `float` (even if an integral number is provided).

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example1": {
                "type": "integer"
            },
            "example2": {
                "type": "number"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample1(?int $example): self;
    public function getExample1(): ?int;

    public function setExample2(?float $example): self;
    public function getExample2(): ?float;

Possible exceptions:

* Invalid type for example. Requires int, got __TYPE__
* Invalid type for example. Requires double, got __TYPE__

Range validation
-----------------

To add a range validation to the property use the `minimum`, `maximum` and `exclusiveMinimum`, `exclusiveMaximum` keywords.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example1": {
                "type": "integer",
                "minimum": 3,
                "maximum": 5
            },
            "example1": {
                "type": "number",
                "minimumExclusive": 1.0,
                "maximumExclusive": 2.0
            }
        }
    }

Possible exceptions:

* Value for example must not be smaller than 3
* Value for example must not be larger than 5
* Value for example must be larger than 1.0
* Value for example must be smaller than 2.0

Multiple of validation
----------------------

To add a multiple of validation to the property use the `multipleOf` keyword.

.. warning::

    If using multipleOf with `number` properties the php core function fmod() will be used for validation following the IEEE-754 standard.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "integer",
                "multipleOf": 3
            }
        }
    }

Possible exceptions:

* Value for example must be a multiple of 3