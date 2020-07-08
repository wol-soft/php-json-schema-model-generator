Numeric types
=============

Used for properties containing numeric values. Properties with the type `integer` are converted to the PHP type `int`, Properties with the type `number` to the type `float` (even if an integral number is provided).

.. code-block:: json

    {
        "$id": "example",
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

    public function setExample1(int $example): self;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getExample1(): ?int;

    public function setExample2(float $example): self;
    public function getExample2(): ?float;

Possible exceptions:

* Invalid type for example. Requires int, got __TYPE__
* Invalid type for example. Requires double, got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Range validation
-----------------

To add a range validation to the property use the `minimum`, `maximum` and `exclusiveMinimum`, `exclusiveMaximum` keywords.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example1": {
                "type": "integer",
                "minimum": 3,
                "maximum": 5
            },
            "example2": {
                "type": "number",
                "minimumExclusive": 1.0,
                "maximumExclusive": 2.0
            }
        }
    }

Possible exceptions:

* Value for example1 must not be smaller than 3
* Value for example1 must not be larger than 5
* Value for example2 must be larger than 1.0
* Value for example2 must be smaller than 2.0

The thrown exception will be one of the following, corresponding to the JSON schema keywords with the methods to get the constraint:

* *PHPModelGenerator\\Exception\\Number\\MinimumException* (public function getMinimum(), returns int or float)
* *PHPModelGenerator\\Exception\\Number\\MaximumException* (public function getMaximum(), returns int or float)
* *PHPModelGenerator\\Exception\\Number\\ExclusiveMinimumException* (public function getExclusiveMinimum(), returns int or float)
* *PHPModelGenerator\\Exception\\Number\\ExclusiveMaximumException* (public function getExclusiveMaximum(), returns int or float)

Each exception additionally provides the following methods:

.. code-block:: php

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Multiple of validation
----------------------

To add a multiple of validation to the property use the `multipleOf` keyword.

.. warning::

    If using multipleOf with `number` properties the php core function fmod() will be used for validation following the IEEE-754 standard.

.. code-block:: json

    {
        "$id": "example",
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

The thrown exception will be a *PHPModelGenerator\\Exception\\Number\\MultipleOfException* which provides the following methods to get further error details:

.. code-block:: php

    // returns the multipleOd constraint
    public function getMultipleOf()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()