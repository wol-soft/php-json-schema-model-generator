Not
===

Used to validate a provided schema or property doesn't match the given schema. In our example object the value for the property example may contain anything except a boolean value to be valid.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "not": {
                    "type": "boolean"
                }
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample($example): self;
    public function getExample();


Possible exceptions:

.. code-block:: none

    Invalid value for property declined by composition constraint.
      Requires to match none composition element but matched 1 elements.
      - Composition element #1: Valid
