Boolean
=======

Used for properties containing boolean values. Converted to the PHP type `bool`.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "boolean"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(bool $example): self;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getExample(): ?bool;

Possible exceptions:

* Invalid type for example. Requires bool, got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
