Composition-implied objects
============================

A property (or composition branch) can be object-shaped without ever declaring
``"type": "object"`` itself — either because a composition guarantees it, or because it carries
keywords that only make sense for objects. The generator detects both cases, but treats them
differently. A composition that *guarantees* object-ness (**object-asserting**) is treated exactly
like an explicit ``"type": "object"``: a non-object value is rejected. A schema that only *carries
object-constraining keywords* (**object-describing**) is merely recognized as object-shaped: a
non-object value still passes through unchanged. A schema that defines its own generated class
can't pass a value through, so it is not turned into a class unless
``setImplicitObjectComposition(true)`` is set.

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

The generator emits a generation-time warning for an object-describing property, array item or
composition branch — since it is easy to write one by accident (forgetting ``"type": "object"``)
and get silent pass-through instead of the intended validation. The warning names the property
and the location of the offending schema:

.. code-block:: none

    Property 'person' at '/properties/person' in file '...' carries object-constraining keywords
    (eg. 'properties', 'required') without a 'type' declaration and does not constrain non-object
    values

.. note::

    A schema ``dependencies`` value is the one site that does **not** take part in this: an
    untyped dependency schema has ``"type": "object"`` forced onto it before any classification
    happens, so it is treated as object-*asserting* — a non-object value is rejected rather than
    passed through — and no warning is emitted. Declare ``"type": "object"`` explicitly there to
    make what you get match what you wrote.

.. note::

    No warning is emitted where an enclosing schema already asserts an object, as nothing is
    implied there: the branches of a composition of a class root or of a property declared as
    ``"type": "object"`` validate that very value, and the target of a ``$ref`` on the root of a
    schema file is the object of the class (the schema of a ``dependencies`` entry is such a site
    as well, see above). This holds for a schema written inline and for a ``$ref`` to a definition
    alike: the reference is judged by the site it is used at, so a definition which is referenced
    from such a branch and from a plain property warns for the property only. The assertion
    covers the value of the branch and nothing below it, a property inside such a definition is
    judged by itself.

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
base-level ``$ref`` schema files all resolve *composition-implied* object-ness the same way (the
object-describing case is the exception noted above: ``dependencies`` force-asserts it):

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

A composition that defines its own generated class — a schema file's root, or a ``$ref`` target
that is parsed as a top-level schema in its own right — must resolve to a **definite** object:
every value the composition accepts has to be representable by the single generated class. Unlike
the property-level detection above, which lets an object-describing property pass non-object
values through unchanged, a class-defining composition has no such escape hatch: by default it
rejects an object-describing composition outright:

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
    represented as a generated class: add an explicit '"type": "object"' constraint, or enable
    'GeneratorConfiguration::setImplicitObjectComposition(true)' to accept it

A composition that resolves to no object at all — one containing a scalar-typed branch, a vacuous
branch such as ``true`` or ``{}``, no branches at all, or a root ``type`` listing more than just
``"object"`` — is rejected too, but with the second half of the message replaced:

.. code-block:: none

    Composition for 'Example' in file '...' does not resolve to a definite object and cannot be
    represented as a generated class: if every value it accepts is meant to be an object, declare
    '"type": "object"' on the schema itself

The opt-in flag is deliberately not offered there: it widens acceptance from object-asserting to
object-describing and no further, so it cannot rescue a composition that genuinely accepts
non-object values. Declaring the type does resolve it, but unlike the object-describing case it
*changes* which values the schema accepts rather than stating what it already means — which is why
the message makes it conditional rather than an instruction.

Declaring ``"type": "object"`` on the schema itself always resolves it to a definite object
regardless of what its branches declare — the explicit type is the assertion, and the branches
only narrow further.

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

    This requirement applies only to a schema file's root and to a ``$ref`` target parsed as its
    own top-level schema. A named property, array item, or schema ``dependencies`` value never
    triggers this rejection. An object-describing schema at a property or an array item follows
    the behaviour described in *Object-describing: bare validation keywords, no type* above: a
    non-object value passes through unchanged instead of being rejected. The schema of a
    ``dependencies`` entry validates the object which holds the property, so it asserts an object
    (see the note about ``dependencies`` in that section).

By default an object-describing composition (as opposed to object-asserting, or a composition that
doesn't resolve to an object at all) is rejected. Enable
``setImplicitObjectComposition(true)`` (see `Configuring the generator
<../gettingStarted.html#implicit-object-composition>`__) to treat an object-describing
class-defining composition exactly as if it had declared ``"type": "object"`` itself, instead of
rejecting it.

A schema file whose root carries only object keywords — a bare ``properties``/``required``
schema with no ``allOf``/``anyOf``/``oneOf``/``if``, no ``$ref`` and no ``type`` — is not a
composition, so it is not rejected. It describes an object without declaring it as well, and the
generated class couldn't pass a non-object value through either: by default no class is generated
for it and the generator warns, naming the class and the file:

.. code-block:: none

    Schema for 'Base' in file '...' carries object-constraining keywords (eg. 'properties',
    'required') without a 'type' declaration and no class is generated for it: add an explicit
    '"type": "object"' constraint, or enable
    'GeneratorConfiguration::setImplicitObjectComposition(true)' to generate it

With ``setImplicitObjectComposition(true)`` the class is generated as if the type was declared,
and so it is by adding ``"type": "object"`` to the root. A root which describes no object at all —
a file which only holds definitions, a root which declares another type, or one which carries
nothing but a ``not`` or annotations — is skipped silently, as a schema directory routinely
contains files which are only meant to be referenced. Each component of an OpenAPI document is
the root of a class of its own and is reported by itself.

Summary
-------

.. list-table::
   :header-rows: 1
   :widths: 50 25 25

   * - Site
     - Default
     - ``setImplicitObjectComposition(true)``
   * - Property, array item, ``additionalProperties`` / ``patternProperties`` value, ... without
       a ``type``
     - Accepted, warning
     - Unchanged
   * - Branch of a composition of a class root or of a property declared as an object,
       ``dependencies`` schema, target of a ``$ref`` on the root of a schema file
     - Accepted, no warning
     - Unchanged
   * - Composition which defines a class (file root, ``$ref`` target parsed as a top-level schema)
     - Rejected with a **SchemaException**
     - Class generated
   * - File root with object keywords only
     - Skipped, warning
     - Class generated
