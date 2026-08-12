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

    public function setExample($example): static;
    public function getExample();


Possible exceptions:

.. code-block:: none

    Invalid value for 'example' declined by composition constraint
      Requires to match none composition element but matched 1 element
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
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

.. note::

    The ``not`` schema can be the boolean literal ``true`` or ``false``.

    - ``not: false`` — negation of the impossible schema; always valid. No validator is generated.
    - ``not: true`` — negation of the always-valid schema; always invalid. Providing any value
      raises a ``NotException`` at runtime. The generator also emits a warning at generation time.

.. hint::

    The ``not`` schema does not need to declare ``"type": "object"`` itself to be treated as an
    object — the generator also detects object-ness implied by a ``$ref`` chain or nested
    ``allOf``. Unlike the other composition keywords, a value forbidden by an implied-object
    ``not`` schema legitimately stays a raw array/associative array rather than being
    instantiated — ``not`` describes what the value must *not* be, so no representation class
    is needed for it.
    See `Composition-implied objects <impliedObjects.html>`__ for the full explanation.
