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

The thrown exception will be a *PHPModelGenerator\\Exception\\ComposedValue\\NotException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by composition elements
    public function getCompositionErrorCollection(): array
    // get the amount of succeeded composition elements
    public function getSucceededCompositionElements(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
