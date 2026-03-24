Cross-typed compositions
========================

When the same property name appears in multiple composition branches with **different types**, the
generator must decide what PHP type hint to emit for that property. The behaviour depends on the
composition keyword used.

anyOf / oneOf: union type widening
-----------------------------------

For ``anyOf`` and ``oneOf``, exactly one branch (or at least one branch for ``anyOf``) applies at
runtime. Because the matching branch is not known at generation time, the generator widens the
property type to the union of all branch types.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "anyOf": [
            {
                "type": "object",
                "properties": {
                    "age": {
                        "type": "integer"
                    }
                }
            },
            {
                "type": "object",
                "properties": {
                    "age": {
                        "type": "string"
                    }
                }
            }
        ]
    }

Generated interface:

.. code-block:: php

    public function setAge(int | string | null $age): static;
    public function getAge(): int | string | null;

The ``age`` property appears in both branches with different types, so the generator produces an
``int | string`` union. It is nullable because the property is optional in each branch — an input
object might satisfy the ``anyOf`` constraint via a branch that carries a different property
entirely, leaving ``age`` absent.

Nullability and required promotion
------------------------------------

A property that is present in some branches but not others is always nullable in the generated
class. The matching branch at runtime may not define the property at all, so the getter must be
able to return ``null``.

When the composition structure **guarantees** that a property will always be present, the generator
promotes the property to non-nullable — regardless of whether ``implicitNull`` is enabled. The
promotion rules depend on the keyword:

* **allOf** — property is promoted when it appears in ``required`` in **any** branch (because all
  branches must hold simultaneously, so any branch's ``required`` constraint applies globally).
* **anyOf** / **oneOf** — property is promoted only when it appears in ``required`` in **every**
  branch (because only one branch applies at runtime; the property is present only if all branches
  guarantee it).
* **if / then / else** — property is promoted only when it appears in ``required`` in **both**
  ``then`` and ``else``. If only a ``then`` block exists (no ``else``), the property is never
  promoted because the ``else`` path may apply at runtime and the property would be absent.

For example, with a two-branch ``oneOf`` where ``age`` is required in both branches:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "oneOf": [
            {
                "type": "object",
                "required": ["age"],
                "properties": {
                    "age": {
                        "type": "integer"
                    }
                }
            },
            {
                "type": "object",
                "required": ["age"],
                "properties": {
                    "age": {
                        "type": "string"
                    }
                }
            }
        ]
    }

Generated interface:

.. code-block:: php

    public function setAge(int | string $age): static;
    public function getAge(): int | string;

Because ``age`` is required in every branch, the generator removes the ``null`` from the union — a
valid input always provides ``age``. If ``age`` were optional in even one branch, the getter would
be ``int | string | null``.

.. note::

    Promotion only affects the PHP type hint (nullability). It does **not** set ``isRequired()``
    to ``true`` on the property. The schema-level ``required`` constraint is still enforced by the
    composition validator, not by a separate required-value check. This means omitting a promoted
    property still raises a composition exception, not a ``RequiredValueException``.

Properties exclusive to a single branch
----------------------------------------

When a property appears in only one branch and the other branches do not declare
``additionalProperties: false``, the generator cannot safely assign a typed slot to that property
— an arbitrary input value could arrive from a non-matching branch. The type hint is widened to
``mixed`` in this case.

If all other branches declare ``additionalProperties: false``, the generator knows no conflicting
value can arrive and preserves the branch type (as nullable).

if / then / else: conditional union widening
---------------------------------------------

The ``if``/``then``/``else`` construct is semantically equivalent to a two-branch ``oneOf``:
exactly one of ``then`` or ``else`` applies at runtime. The same union-widening rules therefore
apply when ``then`` and ``else`` define the same property with different types.

See `If <if.html>`__ for examples and further details.

Root-level property precedence
--------------------------------

When a property is defined in the root ``properties`` section **and** in a composition branch, the
root definition takes precedence. Composition branches may add further constraints but will not
widen the property type.

.. note::

    The same intersection behaviour also applies to properties defined via
    ``patternProperties`` when their names match declared properties. See
    `Pattern properties <../object/patternProperties.html>`__ for details.