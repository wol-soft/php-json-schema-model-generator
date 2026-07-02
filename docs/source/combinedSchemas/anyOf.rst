Any Of
======

The `anyOf` keyword can be used to combine multiple subschemas. The provided value must be valid against at least one of the subschemas.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "anyOf": [
                    {
                        "type": "number",
                        "multipleOf": 5
                    },
                    {
                        "type": "number",
                        "multipleOf": 3
                    }
                ]
            }
        }
    }

Valid values are eg. 3, 5, 6, 9, 10, 12, 15. Invalid values are eg. 1, 2, 4, 7, 8, 11 or any non numeric values.

Generated interface:

.. code-block:: php

    public function setExample(float $example): static;
    public function getExample(): float;


Possible exception (if a string is provided):

.. code-block:: none

    Invalid value for example declined by composition constraint.
      Requires to match at least one composition element.
      - Composition element #1: Failed
        * Invalid type for example. Requires float, got string
      - Composition element #2: Failed
        * Invalid type for example. Requires float, got string

The thrown exception will be a *PHPModelGenerator\\Exception\\ComposedValue\\AnyOfException* which provides the following methods to get further error details:

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

    ``anyOf`` branches can be the boolean literals ``true`` or ``false``.

    - ``true`` branch — always satisfies the branch; treated as an empty schema.
    - ``false`` branch — can never be satisfied; always-failing branches participate in the
      composition but never succeed. If all branches are ``false``, any provided value raises an
      ``AnyOfException`` at runtime, and the generator emits a warning at generation time.
      Absent optional properties are still allowed.

.. hint::

    When combining multiple nested objects with an `anyOf` composition a `merged property <mergedProperty.html>`__ will be generated

.. note::

    When a property is also defined in the root ``properties`` section, the root type definition
    is authoritative. ``anyOf`` branches may add further constraints but will not widen the
    property's type. When branches define the same property with different types, the generator
    widens the property to a union type. See
    `Cross-typed compositions <crossTypedComposition.html>`__ for the full explanation including
    nullability rules and the ``allOf`` contrast.

.. note::

    For object-level ``anyOf`` compositions, when a property appears in the ``required`` array of
    **every** branch, the generator promotes that property to non-nullable in the generated class.
    Because at least one branch must apply and all branches guarantee the property's presence, the
    getter can safely be non-nullable. See `Cross-typed compositions <crossTypedComposition.html>`__
    for the full promotion rules.

.. note::

    Properties in object-level ``anyOf`` branches may carry a ``"default"`` value. The generator
    applies the branch default only when that branch is among the active ones — determined at
    construction time by which branches the provided data satisfies. When multiple matching branches
    define a default for the same property, those defaults must agree; the generator throws a
    ``SchemaException`` at generation time if they differ. Branch defaults are **not** included in
    ``getRawModelDataInput()``.

    See `Default values <../generic/default.html#branch-defaults-in-compositions>`__ for the full
    explanation.
