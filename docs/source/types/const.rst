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
