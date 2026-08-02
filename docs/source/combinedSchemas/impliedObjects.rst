Composition-implied objects
============================

A property (or composition branch) can be object-shaped without ever declaring
``"type": "object"`` itself — either because a composition guarantees it, or because it carries
keywords that only make sense for objects. The generator detects both cases and treats them the
same way it treats an explicit ``"type": "object"``.

Object-asserting: composition guarantees object-ness
-------------------------------------------------------

An ``allOf`` whose branches, taken together, guarantee that every valid value is an object is
**object-asserting** even if no individual branch (and not the property itself) declares
``"type": "object"`` directly — for example an ``allOf`` of ``$ref``\ s that each resolve
(possibly through further nested ``allOf``/``$ref`` chains) to an object schema:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "person": {
                "allOf": [
                    { "$ref": "#/definitions/identification" },
                    { "$ref": "#/definitions/employment" }
                ]
            }
        },
        "definitions": {
            "identification": {
                "allOf": [
                    {
                        "type": "object",
                        "properties": { "name": { "type": "string" } },
                        "required": ["name"]
                    }
                ]
            },
            "employment": {
                "type": "object",
                "properties": { "age": { "type": "integer" } }
            }
        }
    }

``person`` is routed through the same object path as an explicit ``"type": "object"`` property: a
real class is generated for it, instantiated for object values, and validated with an
unconditional ``instanceof`` check. Neither ``person`` nor ``identification`` declares its own
``type``; the generator establishes object-ness by resolving ``$ref`` chains and ``allOf``
branches statically at generation time. Both ``name`` (from ``identification``) and ``age`` (from
``employment``) end up as accessors on the same generated class for ``person``:

.. code-block:: php

    public function getPerson(): ?PersonClass;

    # PersonClass
    public function getName(): string;
    public function getAge(): ?int;

This re-routing applies wherever a property is processed — root level, nested properties, array
items, schema ``dependencies`` values, and base-level ``$ref`` schema files alike — but only for
``allOf`` as the *outer* keyword. ``anyOf``/``oneOf``/``if``/``not`` keep each branch's own runtime
value identity and are fixed at the branch level instead: a branch that is itself an
object-asserting ``allOf`` (or ``$ref`` to one) is routed through the object path when it is
built, but the outer ``anyOf``/``oneOf``/``if``/``not`` composition itself is never collapsed into
a single class the way an outer ``allOf`` is.

.. note::

    Mixing an object-asserting branch with a scalar-typed branch in the same ``allOf`` is a
    generation-time conflict, exactly as it is for an explicit ``"type": "object"`` branch — see
    the conflicting-types note in `All Of <allOf.html>`__.

Object-describing: bare validation keywords, no type
---------------------------------------------------------

A property or branch that carries only object-constraining keywords (``properties``,
``required``, ``patternProperties``, ``additionalProperties``, ``propertyNames``,
``minProperties``, ``maxProperties``, ``dependencies``) and no ``type`` at all is
**object-describing**. Per strict JSON Schema semantics, these keywords constrain object values
but are vacuously satisfied by any non-object value:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "person": {
                "properties": {
                    "name": { "type": "string" }
                },
                "required": ["name"]
            }
        }
    }

.. code-block:: php

    public function setPerson(mixed $person): static;
    public function getPerson(): mixed;

An object value is instantiated and validated against the declared constraints; a non-object
value passes through completely unchanged — so the getter/setter type hint stays ``mixed``
instead of the representation class, since a non-object value would otherwise violate a typed
return. Passing ``["name" => "Hannes"]`` returns an instantiated representation object; passing
``42`` returns ``42`` unchanged; passing ``[]`` (an object missing the required ``name``) is
rejected:

.. code-block:: none

    Invalid nested object for property 'person':
      - Missing required value for 'name'

The generator emits a generation-time warning for a bare object-describing property or branch —
one that carries the object-constraining keywords directly, without a ``$ref`` or an enclosing
``allOf``/``anyOf``/``oneOf``/``if``/``not`` — since it is easy to write one by accident
(forgetting ``"type": "object"``) and get silent pass-through instead of the intended validation.
An object-describing schema reached through a ``$ref``, or wrapped in one of those composition
keywords, produces no warning:

.. code-block:: none

    Property 'person' carries object-constraining keywords (eg. 'properties', 'required')
    without a 'type' declaration and does not constrain non-object values

.. note::

    An object-describing branch does **not** conflict with a scalar-typed sibling in the same
    ``allOf`` — unlike the object-asserting case above, it is vacuously satisfied by non-object
    values, so e.g. ``allOf: [{properties: {...}, required: [...]}, {type: "string"}]`` is
    satisfiable (by any string that also happens to satisfy the ``properties``/``required``
    constraints when it *is* an object — which a string never is, so in practice only the scalar
    branch's constraint is ever checked).

anyOf / oneOf with object-describing branches
-------------------------------------------------

Because an object-describing branch is vacuously satisfied by any non-object value, a non-object
value satisfies **every** bare-validator branch in a composition at once. This matters for
``oneOf``, which requires *exactly one* branch to match:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "person": {
                "oneOf": [
                    { "properties": { "name": { "type": "string" } }, "required": ["name"] },
                    { "properties": { "companyName": { "type": "string" } }, "required": ["companyName"] }
                ]
            }
        }
    }

A non-object value such as ``42`` vacuously satisfies both bare branches at once, which violates
``oneOf``'s "exactly one" requirement, so it is rejected:

.. code-block:: none

    Invalid value for 'person' declined by composition constraint
      Requires to match one composition element but matched 2 elements
      - Composition element #1: Valid
      - Composition element #2: Valid

For ``anyOf`` (which only requires *at least one* match), the same non-object value is accepted,
since matching every branch still satisfies "at least one."

Array items and other schema-creation contexts
---------------------------------------------------

Composition-implied object detection applies everywhere a subschema is turned into a property or
class, not just to named object properties — array ``items``, schema ``dependencies`` values, and
base-level ``$ref`` schema files all resolve object-ness the same way:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "members": {
                "type": "array",
                "items": { "$ref": "#/definitions/person" }
            }
        },
        "definitions": {
            "person": {
                "allOf": [
                    {
                        "type": "object",
                        "properties": { "name": { "type": "string" } },
                        "required": ["name"]
                    }
                ]
            }
        }
    }

Each array item is instantiated and validated exactly like an item referencing an explicit
``"type": "object"`` definition would be, even though ``person`` never declares its own ``type``.

Class-defining compositions must resolve to a definite object
-------------------------------------------------------------

A composition that defines its own generated class — a schema file's root, a ``$ref`` target that
is parsed as a top-level schema in its own right, and (via the re-routing above) any property,
array item, or schema ``dependencies`` value whose composition is object-asserting or
object-describing — must resolve to a **definite** object: every value the composition accepts has
to be representable by the single generated class. This is stricter than the property-level
detection above, and by default rejects what that detection alone would let through as
object-describing:

.. code-block:: json

    {
        "$id": "example",
        "oneOf": [
            { "properties": { "name": { "type": "string" } }, "required": ["name"] },
            { "properties": { "companyName": { "type": "string" } }, "required": ["companyName"] }
        ]
    }

Neither branch declares ``"type": "object"``, so the composition as a whole is
object-describing, not object-asserting — per strict JSON Schema semantics a non-object value
(e.g. a bare string) is a valid instance of this schema too, but there is no non-object
representation this generated class could produce for it. Generation is rejected:

.. code-block:: none

    Composition for 'Example' in file '...' does not resolve to a definite object and cannot be
    represented as a generated class: enable 'GeneratorConfiguration::setImplicitObjectComposition(true)'
    to accept it

Declaring ``"type": "object"`` on the schema itself always resolves it to a definite object
regardless of what its branches declare — the explicit type is the assertion, and the branches
only narrow further. See `Migrating existing schemas`_ below if a schema that used to generate
now raises this exception.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "oneOf": [
            { "properties": { "name": { "type": "string" } }, "required": ["name"] },
            { "properties": { "companyName": { "type": "string" } }, "required": ["companyName"] }
        ]
    }

An ``if``/``then``/``else`` composition resolves to a definite object only when both the ``then``
and the ``else`` branch do — every value takes exactly one of the two paths, so the whole
composition is only guaranteed object-ness when both paths are. A missing ``then`` or ``else``
leaves that path fully unconstrained and is treated the same as a branch that doesn't assert
object-ness.

.. note::

    Today this check only has something to reject for a composition that is genuinely ambiguous
    on its own — a schema file's root, or a ``$ref`` target parsed as its own top-level schema. A
    named property, array item, or schema ``dependencies`` value reaches the object path via the
    composition-implied-object detection described above, which forces ``"type": "object"`` onto
    the JSON before this check runs — so by the time the check is consulted the classification
    has already resolved to a definite object, leaving nothing for it to reject.

By default an object-describing composition (as opposed to object-asserting, or a composition that
doesn't resolve to an object at all) is rejected. Enable
``setImplicitObjectComposition(true)`` (see `Configuring the generator
<../gettingStarted.html#implicit-object-composition>`__) to treat an object-describing
class-defining composition exactly as if it had declared ``"type": "object"`` itself, instead of
rejecting it.

Migrating existing schemas
-------------------------------

Object-shape detection was tightened, and a gap in how a base-level ``$ref`` was validated was
fixed, together in the same change. Both can affect schemas that generated cleanly before. This
section lists what changed and exactly what to edit if you hit it.

Schemas that used to generate now raise a SchemaException
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The "class-defining compositions must resolve to a definite object" check above is new. Two
schema shapes that used to be accepted without complaint are now rejected because they only
describe object shape rather than asserting it.

**The common OpenAPI inheritance shape, when the base schema omits ``"type": "object"``:**

.. code-block:: json

    {
        "allOf": [
            { "$ref": "Base.json" },
            { "properties": { "name": { "type": "string" } } }
        ]
    }

with ``Base.json``:

.. code-block:: json

    {
        "properties": { "id": { "type": "string" } }
    }

Neither the base schema nor the second branch declares ``"type": "object"``, so the composition
as a whole is object-describing rather than object-asserting (see above), and generation is now
rejected.

**A schema file root that carries ``properties`` together with an ``if``/``then`` and no
``else``, and no ``type`` of its own:**

.. code-block:: json

    {
        "properties": { "name": { "type": "string" } },
        "if": { "properties": { "name": { "const": "special" } } },
        "then": { "required": ["name"] }
    }

An ``if`` without a matching ``else`` leaves the non-matching path fully unconstrained, so the
composition does not guarantee object-ness for every value it accepts, and no longer resolves to
a definite object either.

Fix either shape one of two ways:

- Add ``"type": "object"`` to the schema — preferred, since it makes the intended shape explicit
  and is what the generator needs to resolve the composition to a definite object.
- Enable ``setImplicitObjectComposition(true)`` (see `Configuring the generator
  <../gettingStarted.html#implicit-object-composition>`__) to keep accepting an
  object-describing class-defining composition as before.

This is not a hypothetical concern: two schemas in this project's own test suite needed
``"type": "object"`` added once this check landed.

Composition error messages changed shape in direct-exception mode
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

With ``setCollectErrors(false)``, a failing ``allOf``/``anyOf``/``oneOf``/``if`` composition now
lists every branch instead of just a bare header. Before:

.. code-block:: none

    Invalid value for 'example' declined by composition constraint
      Requires to match all composition elements but matched 0 elements

After:

.. code-block:: none

    Invalid value for 'example' declined by composition constraint
      Requires to match all composition elements but matched 0 elements
      - Composition element #1: Failed
        * Invalid type for 'example': requires 'float', got 'string'
      - Composition element #2: Failed
        * Invalid type for 'example': requires 'float', got 'string'

Each branch is now listed as ``- Composition element #N: Valid`` or ``- Composition element #N:
Failed`` with its underlying reason, in schema order — the same shape ``setCollectErrors(true)``
already produced, so the two modes now agree. If anything in your code or tests matches
composition exception text, expect these extra lines.

A base-level $ref now enforces the referenced schema's own object-level constraints
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

When a schema file's entire top level is a single ``{"$ref": "..."}``, the referenced schema's
own object-level constraints — ``additionalProperties``, ``minProperties``, ``maxProperties``,
``propertyNames``, and any root-level composition (``allOf``/``anyOf``/``oneOf``/``if``) — were
previously dropped while transferring the referenced properties onto the referencing schema, and
so were never validated. They are now enforced. This is a bug fix, but it means input that used
to be accepted may now be correctly rejected.

.. note::

    The sharpest case is a referenced root ``allOf``: it never ran at all before, so a class
    could be constructed successfully from data that violated the composition, and only fail
    later with a plain PHP ``TypeError`` from a typed getter — instead of failing at
    construction time with a clear validation exception, as it does now.
