Const
=====

Used for properties which only accept a single value.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "const": 42
            }
        }
    }

Generated interface (the typehint is auto-detected from the given constant value):

.. code-block:: php

    public function setExample(int $example): static;
    public function getExample(): int;

Possible exceptions:

* Value for 'example' must be 42, got 41

.. hint::

    A property with the constant `null` is typed with the `null` type of PHP, see `Null <null.html>`__.

The constant may also be an object or an array. The provided value is compared with the constant as JSON data (the order of the keys of an object doesn't matter, ``1`` equals ``1.0`` inside of an array or an object), see `Object and array values <../complexTypes/enum.html#object-and-array-values>`__.

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidConstException* which provides the following methods to get further error details:

.. code-block:: php

    // returns the expected value of the const property
    public function getExpectedValue()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer
