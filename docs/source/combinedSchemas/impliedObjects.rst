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

The generator emits a generation-time warning for every object-describing property or branch,
since it is easy to write one by accident (forgetting ``"type": "object"``) and get silent
pass-through instead of the intended validation:

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
