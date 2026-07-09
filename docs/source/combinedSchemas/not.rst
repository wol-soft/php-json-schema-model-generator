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
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

.. note::

    The ``not`` schema can be the boolean literal ``true`` or ``false``.

    - ``not: false`` — negation of the impossible schema; always valid. No validator is generated.
    - ``not: true`` — negation of the always-valid schema; always invalid. Providing any value
      raises a ``NotException`` at runtime. The generator also emits a warning at generation time.

Property and item evaluation propagation
----------------------------------------

For an enclosing schema that uses `unevaluatedProperties <../complexTypes/object.html#unevaluated-properties>`__
or `unevaluatedItems <../complexTypes/array.html#unevaluated-items>`__ (Draft 2019-09 and later),
``not`` is a **negative** applicator and contributes nothing. ``not`` succeeds when its inner
subschema *fails*, so any keys or indices touched by that inner subschema are, by definition,
not evaluated. The generator explicitly discards anything the inner subschema tried to record —
even an inner ``unevaluatedProperties``/``unevaluatedItems`` cannot leak into the enclosing
accumulator.
