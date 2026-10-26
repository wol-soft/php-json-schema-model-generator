All Of
======

The `allOf` keyword can be used to combine multiple subschemas. The provided value must be valid against each of the subschemas.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "allOf": [
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

Valid values are eg. 15, 30, 45. Invalid values are eg. 1, 2, 3, 4, 5 or any non numeric values.

Generated interface:

.. code-block:: php

    public function setExample(float $example): static;
    public function getExample(): float;


Possible exception (eg. if a string is provided):

.. code-block:: none

    Invalid value for 'example' declined by composition constraint
      Requires to match all composition elements but matched 0 elements
      - Composition element #1: Failed
        * Invalid type for 'example': requires 'float', got 'string'
      - Composition element #2: Failed
        * Invalid type for 'example': requires 'float', got 'string'

Possible exception (if eg. 5 is provided, which matches only one subschema):

.. code-block:: none

    Invalid value for 'example' declined by composition constraint
      Requires to match all composition elements but matched 1 element
      - Composition element #1: Valid
      - Composition element #2: Failed
        * Value for 'example' must be a multiple of 3

The thrown exception will be a *PHPModelGenerator\\Exception\\ComposedValue\\AllOfException* which provides the following methods to get further error details:

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

.. hint::

    When combining multiple nested objects with an `allOf` composition the property is typed with a single generated class holding all of their properties. Unlike `anyOf`/`oneOf` this is a regular nested class rather than a `merged property <mergedProperty.html>`__: an `allOf` of object branches guarantees that every valid value is an object, so it is routed through the ordinary object path — see `Composition-implied objects <impliedObjects.html>`__.

.. hint::

    An ``allOf`` branch does not need to declare ``"type": "object"`` itself to be treated as an
    object — the generator also detects object-ness implied by a ``$ref`` chain or nested
    ``allOf``, and object-constraining keywords used without any ``type`` at all. See
    `Composition-implied objects <impliedObjects.html>`__ for the full explanation, including why
    an object-*describing* branch (bare ``properties``/``required``, no ``type``) does **not**
    trigger the conflicting-types check below the way an object-*asserting* branch does.

.. note::

    ``allOf`` branches can be the boolean literals ``true`` or ``false``.

    - ``true`` branch — treated as an empty schema; any value satisfies it and it adds no constraint.
      The generator emits a generation-time warning that the branch carries no validation keyword
      and matches any value.
    - ``false`` branch — makes the whole composition unsatisfiable; any provided value raises an
      ``AllOfException`` at runtime (the false branch is represented as an always-failing composition
      element). The generator also emits a warning at generation time. Absent optional properties
      are still allowed. An ``allOf`` with a ``false`` branch on the object itself rejects every
      object.

    An empty ``{}`` branch, and a branch carrying only annotation keywords such as ``example``,
    behave identically to ``true`` at runtime — they impose no constraint either, and they are
    warned about in the same way. A ``type`` which a branch inherits from the enclosing schema (a
    schema root always has ``"type": "object"`` applied to it) is not a constraint of the branch
    itself, so it doesn't suppress the warning; only a ``type`` declared by the branch does. A branch
    consisting of a ``$ref`` is judged by the schema it references and only warned about if that schema
    matches any value as well.

.. note::

    When a property is defined in multiple ``allOf`` branches with conflicting types (e.g. one branch
    requires ``string`` and another requires ``integer``), the generator will throw a ``SchemaException``
    at generation time. ``allOf`` requires all constraints to hold simultaneously, so conflicting types
    make the schema unsatisfiable. When branches agree on a type, the generator narrows the property to
    the intersection of all declared types across branches. See
    `Cross-typed compositions <crossTypedComposition.html>`__ for the full explanation and a contrast
    with ``anyOf``/``oneOf`` union widening.

.. note::

    A branch which declares no ``type`` inherits the ``type`` of its schema (a schema root always has
    ``"type": "object"`` applied to it) and has to be satisfiable together with it. An ``enum`` or ``const`` of
    the branch which lists no value of the inherited type (e.g. ``{"enum": [1, 2, 3]}`` inside an object schema)
    can never hold. As every ``allOf`` branch has to hold, the schema is unsatisfiable and the generator throws a
    ``SchemaException`` at generation time. The branches of the other composition keywords are only dead in this
    case, so the generator emits a warning for them instead.

.. note::

    For object-level ``allOf`` compositions, when a property appears in the ``required`` array of
    **any** branch, the generator promotes that property to non-nullable in the generated class. All
    ``allOf`` branches must hold simultaneously, so any branch's ``required`` constraint is effectively
    global. See `Cross-typed compositions <crossTypedComposition.html>`__ for the full promotion rules.

.. note::

    Properties in object-level ``allOf`` branches may carry a ``"default"`` value. Because all
    branches apply simultaneously, defaults from every branch are combined. When multiple branches
    define a default for the same property, those defaults must agree; the generator throws a
    ``SchemaException`` at generation time if they differ.

    See `Default values <../generic/default.html#branch-defaults-in-compositions>`__ for the full
    explanation.

.. note::

    A property in an object-level ``allOf`` branch may declare a ``"filter"``, all branches are always
    active. If the schema itself declares the property without a filter, it adopts the filter of the branch.
    Several branches may declare an identical filter list, the filters are executed once. A property which is
    filtered more than once with diverging filters (in several branches, or by the schema itself and a branch)
    is rejected with a ``SchemaException``, as the filters of a property have no defined order.
    See `Filters on properties of object branches <../nonStandardExtensions/filter.html#filters-on-properties-of-object-branches>`__.

Property and item evaluation propagation
----------------------------------------

For an enclosing schema that uses `unevaluatedProperties <../complexTypes/object.html#unevaluated-properties>`__
or `unevaluatedItems <../complexTypes/array.html#unevaluated-items>`__ (Draft 2019-09 and later),
every ``allOf`` branch always contributes to the evaluated set — ``allOf`` requires every branch
to succeed, so all branches' declarations apply.

For an object-level composition:

- Property names declared in each branch's ``properties`` count as evaluated.
- Names matched by each branch's ``patternProperties`` count as evaluated (per key with a passing
  value).
- Names claimed by each branch's ``additionalProperties`` count as evaluated (per key with a
  passing value).

For a value-typed ``array`` composition, the analogous indices contributed by each branch's
``items``/``additionalItems``/``contains`` count as evaluated.

A composition nested inside a branch (an ``anyOf`` inside an ``allOf`` branch, a conditional
inside a ``oneOf`` branch, ...) contributes the claims of its own successful branches to the
enclosing branch, to any depth.

.. note::

    *Omitting* ``additionalProperties`` from a branch is **not** the same as writing
    ``additionalProperties: true``. An omitted keyword produces no annotation and therefore
    credits nothing to the enclosing ``unevaluatedProperties`` — only an *explicit*
    ``additionalProperties`` (whether ``true`` or ``{schema}``) contributes. Two branches with
    identical extras behaviour but one writing the keyword and the other omitting it will
    therefore credit different evaluated sets. This is a spec-mandated distinction from
    JSON Schema 2019-09. The same rule applies on the array side: an explicit ``additionalItems``
    credits the indices past the tuple, and an explicit ``items: true`` or ``items: {}`` credits
    every index. An explicit ``unevaluatedProperties: true`` or ``unevaluatedItems: true`` inside
    a branch likewise credits every key or index, while omitting these keywords credits nothing.

.. note::

    Only this *up* direction (a branch's own declarations propagating to an enclosing
    ``unevaluatedProperties``/``unevaluatedItems``) is implemented. The reverse — a branch's own
    ``unevaluatedProperties``/``unevaluatedItems`` (other than a literal ``true``, which never
    rejects anything) seeing property names or indices declared by the *enclosing* schema or a
    *sibling* branch — is not currently supported. Generation throws
    ``PHPModelGenerator\Exception\UnsupportedSchemaFeatureException`` for a branch shaped like
    this:

    .. code-block:: json

        {
            "type": "object",
            "properties": { "name": { "type": "string" } },
            "allOf": [ { "unevaluatedProperties": false } ]
        }

    since the branch has no way to know that ``name`` is already declared and validated by the
    enclosing schema.
