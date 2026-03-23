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

Nullability
-----------

A property that is present in some branches but not others is always nullable in the generated
class. The matching branch at runtime may not define the property at all, so the getter must be
able to return ``null``.

If a property is marked as ``required`` in **every** branch that defines it, and at least one
branch is guaranteed to apply, the property may be non-nullable.

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

    The same type-widening and intersection behaviour also applies to properties defined via
    ``patternProperties`` when their names match declared properties. See
    `Pattern properties <../object/patternProperties.html>`__ for details.