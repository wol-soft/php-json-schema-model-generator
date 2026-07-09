UnevaluatedPropertiesAccessorPostProcessor
==========================================

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());

The **UnevaluatedPropertiesAccessorPostProcessor** adds methods to your model to work with
`unevaluated properties <../../complexTypes/object.html#unevaluated-properties>`__ (Draft 2019-09
and later) on your objects. The post processor is only effective when the enclosing schema can
actually accumulate unevaluated keys — that is, when ``additionalProperties`` is absent or set to
``true`` at the same level. If ``additionalProperties`` is ``false`` or a ``{schema}``, every extra
key is already either rejected or claimed and validated by that keyword, so nothing ever reaches
the unevaluated bucket. In those cases the accessor emits no methods and no backing field; the
``unevaluatedProperties`` validator continues to run as a pure assertion.

.. note::

    If the `deny additional properties setting <../../gettingStarted.html#deny-additional-properties>`__
    is set to true the accessor is skipped for the same reason: every schema that does not define
    ``additionalProperties`` behaves as if it had ``additionalProperties: false``.

Added methods
~~~~~~~~~~~~~

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        },
        "unevaluatedProperties": {
            "type": "string"
        }
    }

Generated interface with the **UnevaluatedPropertiesAccessorPostProcessor**:

.. code-block:: php

    public function setExample(string $example): static;
    public function getExample(): ?string;

    public function unevaluatedProperties(): ExampleUnevaluatedProperties;

Because the example schema constrains unevaluated values to ``string``, the generator produces a
typed companion class ``ExampleUnevaluatedProperties`` whose signatures are narrowed to the
declared type:

.. code-block:: php

    /** @return string[] */
    public function getAll(): array;
    public function get(string $key): ?string;
    public function set(string $key, string $value): static;
    public function remove(string $key): bool;

.. note::

    The methods **set** and **remove** on the accessor are only available if the
    `immutable setting <../../gettingStarted.html#immutable-classes>`__ is set to false. Immutable
    models return a read-only companion exposing only ``get`` and ``getAll``.

When ``unevaluatedProperties`` is ``true`` (or the subschema is untyped), no companion is
generated and the accessor is the bare production-library class
``UnevaluatedPropertiesAccessor`` (or ``ImmutableUnevaluatedPropertiesAccessor`` for immutable
models). Its signatures fall back to ``mixed`` while ``set`` keeps the fluent ``static`` return:

.. code-block:: php

    public function getAll(): array;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): static;
    public function remove(string $key): bool;

**getAll**: Returns all unevaluated properties currently held by the model as key-value pairs.
Properties defined in the schema (in this case *example*) and properties claimed by successful
composition branches are not included. Values are the processed values — for typed schemas, an
array of the target type; for a `filter <../../nonStandardExtensions/filter.html>`__ result, the
filtered (and for transforming filters, transformed) values.

**get**: Returns the current value of a single unevaluated property. Returns null if the requested
property does not exist. Like ``getAll``, returns the processed value.

**set**: Adds or updates an unevaluated property. Re-runs the enclosing schema's validation
against the new state, including composition re-evaluation, so a mutation that would move a key
into the coverage of another sibling applicator (or vice versa) is rejected up front. Throws
*RegularPropertyAsUnevaluatedPropertyException* if the key conflicts with a regularly-defined
schema property.

**remove**: Removes an existing unevaluated property from the model. Returns true if the property
was removed, false if it did not exist. May throw a *MinPropertiesException* if removal would
produce an invalid model state.

Serialization
~~~~~~~~~~~~~

When the **UnevaluatedPropertiesAccessorPostProcessor** is applied and
`serialization <../../gettingStarted.html#serialization-methods>`__ is enabled, the unevaluated
properties are merged into the serialization result. If unevaluated properties are processed via
a transforming filter, each value is serialized via the serialization method of the transforming
filter.
