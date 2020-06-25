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

    public function setExample(int $example): self;
    public function getExample(): int;

Possible exceptions:

* Invalid value for example declined by const constraint

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidConstException* which provides the following methods to get further error details:

.. code-block:: php

    // returns the expected value of the const property
    public function getExpectedValue()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
