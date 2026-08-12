Merged Property
===============

If multiple subschemas are combined with `anyOf` and the subschemas contain multiple nested objects all properties of the nested objects will be merged together in a single object representing all composition elements.

If the composition is used on object level no merged property will be generated as the object itself works as a merged property holding all properties of the nested objects from the composition subschemas.

.. note::

    `anyOf` is the only composition keyword that produces a merged property. The other two reach a single class by a different route, or not at all:

    - `allOf` of object branches guarantees that every valid value is an object, so the property is routed through the ordinary object path and typed with a regular nested class instead — see `Composition-implied objects <impliedObjects.html>`__ and the comparison below.
    - `oneOf` matches exactly one branch, so the value keeps that branch's own class; the property stays `mixed` and its annotation lists the branch classes as a union.

For example we combine two objects with `anyOf` for an object property:

.. code-block:: json

    {
        "$id": "company",
        "type": "object",
        "properties": {
            "ceo": {
                "anyOf": [
                    {
                        "type": "object",
                        "properties": {
                            "name": {
                                "type": "string"
                            }
                        }
                    },
                    {
                        "type": "object",
                        "properties": {
                            "age": {
                                "type": "integer"
                            }
                        }
                    }
                ]
            }
        }
    }

This schema will generate four classes. The main class will be `Company`, two classes to validate the subschemas combined with the `anyOf` independent and one merged class containing all properties of the CEO (name and age in this example).
As the subschemas don't contain IDs they will be named with a hash of their content (compare the `naming of classes <../complexTypes/object.html#naming>`__):

* Company.php
* Company_Ceo91970cbb844ec1beb624eaa26295bd8d.php
* Company_Ceoc51f76a84e24113bdb256d8bab180156.php
* Company_Merged_Ceo.php

The classes Company_Ceo91970cbb844ec1beb624eaa26295bd8d and Company_Ceoc51f76a84e24113bdb256d8bab180156 are only used for internal validation and can't be accessed via the generated interface of Company.

Generated interface:

.. code-block:: php

    # class Company
    public function setCeo(mixed $ceo): static;

    /** @return Company_Merged_Ceo|null */
    public function getCeo(): mixed;

    # class Company_Merged_Ceo
    public function getName(): ?string
    public function setName(string $name): static
    public function getAge(): ?int
    public function setAge(int $age): static

The accessors stay ``mixed`` — an `anyOf` does not guarantee that a matching value is an object — so the merged class is named in the annotation instead of the signature. Combine the same two objects with `allOf` and no merged class is created at all: the composition does guarantee an object, so it is routed through the object path and the property is typed with a single regular nested class:

* Company.php
* Company_Ceo.php
* Company_Company_Ceo91970cbb844ec1beb624eaa26295bd8d.php
* Company_Company_Ceoc51f76a84e24113bdb256d8bab180156.php

.. code-block:: php

    # class Company
    public function setCeo(Company_Ceo $ceo): static;
    public function getCeo(): ?Company_Ceo;

If your composition is defined on object level the object will gain access to all properties of the combined schemas:

.. code-block:: json

    {
        "$id": "CEO",
        "type": "object",
        "allOf": [
            {
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                }
            },
            {
                "type": "object",
                "properties": {
                    "age": {
                        "type": "integer"
                    }
                }
            }
        ]
    }

This schema will generate three classes as no merged property is created. The main class will be `CEO` and two classes will be generated to validate the subschemas combined with the `allOf` independent:

* CEO.php
* CEO_CEO91970cbb844ec1beb624eaa26295bd8d.php
* CEO_CEOc51f76a84e24113bdb256d8bab180156.php

.. code-block:: php

    # class CEO
    public function getName(): ?string
    public function setName(string $name): static
    public function getAge(): ?int
    public function setAge(int $age): static

Branch-constraint isolation
---------------------------

When the same property name appears in multiple composition branches with different constraints
(e.g. one ``oneOf`` branch requires an ``enum`` value while another allows a free-form string),
the outer merged class does **not** inherit branch-specific constraints from any individual
branch. Validation constraints such as ``enum``, ``minLength``, or ``pattern`` remain scoped to
their respective branch and are enforced only when that branch is being validated.

This means that the ``EnumPostProcessor`` and similar post-processors will correctly operate on
the branch-level property — not on the outer merged property — so the generated merged class
setter accepts the full union of allowed values from all branches rather than being narrowed to
the constraints of a single branch.

Attributes for shared properties
---------------------------------

When ``JSON_POINTER`` or ``JSON_SCHEMA`` attributes are enabled (see
`Attributes <../gettingStarted.html#attributes>`__), properties that appear in more than one
composition branch receive synthesised attributes:

- **JSON_POINTER**: one ``#[JsonPointer]`` attribute is emitted per branch that defines the
  property. When the property is also declared in the root ``properties`` block, the root
  pointer is prepended.

- **JSON_SCHEMA**: a single ``#[JsonSchema]`` attribute is emitted whose value is a synthesised
  JSON object containing the composition keyword (``allOf``, ``anyOf``, ``oneOf``, or
  ``if``/``then``/``else`` sub-objects) with only those branch sub-schemas that involve this
  property. Root-level constraints are merged into the top-level object when the property is
  also root-registered.
