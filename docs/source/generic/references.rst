References
==========

References can be used to re-use parts/objects of JSON-Schema definitions.

Supported reference types
-------------------------

* internal (in a single file) self-reference to the enclosing scope, for recursive schemas (example: `"$ref": ""`, equivalent to `"$ref": "#"`)
* internal (in a single file) reference by id (example: `"$ref": "#IdOfMyObject"`)
* internal (in a single file) reference by path using ``definitions`` (Draft 7, example: `"$ref": "#/definitions/myObject"`)
* internal (in a single file) reference by path using ``$defs`` (Draft 2019-09, example: `"$ref": "#/$defs/myObject"`)
* relative reference based on the location on the file system to a complete file (example: `"$ref": "./../modules/myObject.json"`)
* relative reference based on the location on the file system to an object by id (example: `"$ref": "./../modules/myObject.json#IdOfMyObject"`)
* relative reference based on the location on the file system to an object by path (example: `"$ref": "./../modules/myObject.json#/definitions/myObject"` or `"$ref": "./../modules/myObject.json#/$defs/myObject"`)
* absolute reference based on the location on the file system to a complete file (example: `"$ref": "/modules/myObject.json"`)
* absolute reference based on the location on the file system to an object by id (example: `"$ref": "/modules/myObject.json#IdOfMyObject"`)
* absolute reference based on the location on the file system to an object by path (example: `"$ref": "/modules/myObject.json#/definitions/myObject"` or `"$ref": "/modules/myObject.json#/$defs/myObject"`)
* network reference to a complete file (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json"`)
* network reference to an object by id (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json#IdOfMyObject"`)
* network reference to an object by path (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json#/definitions/myObject"` or `"$ref": "https://my.domain.com/schema/modules/myObject.json#/$defs/myObject"`)

If an `$id` is present in the schema, the `$ref` will be resolved relative to the `$id` (except the `$ref` already is an absolute reference, e.g. a full URL).
The behaviour of `$ref` resolving can be overwritten by implementing a custom **SchemaProviderInterface**, for example when you want to use network references behind an authorization.

.. note::

    For absolute local references, the default implementation traverses up the directory tree until it finds a matching file to find the project root

Object reference
----------------

An example for properties referring to a definition inside the same schema (Draft 7 using ``definitions``):

.. code-block:: json

    {
        "definitions": {
            "person": {
                "$id": "#person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                }
            }
        },
        "$id": "https://example.com/schemas/team",
        "type": "object",
        "properties": {
            "leader": {
                "$ref": "#person"
            },
            "members": {
                "type": "array",
                "items": {
                    "$ref": "#/definitions/person"
                }
            }
        }
    }

Draft 2019-09 introduced ``$defs`` as the standard replacement for ``definitions``. Both keywords are supported and behave identically:

.. code-block:: json

    {
        "$schema": "https://json-schema.org/draft/2019-09/schema",
        "$defs": {
            "person": {
                "$id": "#person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                }
            }
        },
        "$id": "https://example.com/schemas/team",
        "type": "object",
        "properties": {
            "leader": {
                "$ref": "#person"
            },
            "members": {
                "type": "array",
                "items": {
                    "$ref": "#/$defs/person"
                }
            }
        }
    }

Self-reference (recursive schemas)
-----------------------------------

An empty ``$ref`` (``"$ref": ""``) references the enclosing document or ``$id`` scope itself,
without needing to name it. This is the standard idiom for recursive schemas, e.g. an object that
may contain further objects of its own type:

.. code-block:: json

    {
        "$id": "#person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string"
            },
            "children": {
                "type": "array",
                "items": {
                    "$ref": ""
                }
            }
        }
    }

``""`` resolves against the *nearest enclosing* ``$id`` (or the document root if none is in
scope) — a ``$ref: ""`` written inside a ``$id``-scoped definition recurses into that definition,
not into the top-level document. It behaves identically to explicitly writing the enclosing
scope's own id (``"$ref": "#person"`` above), just without needing to name it.

Using an empty (or ``"#"``) ``$ref`` at a schema's own base level (see `Base Reference`_ below)
is rejected instead: it would ask the schema to be merged with itself, which has no fixed point
to compute.

Base Reference
--------------

The whole model may contain a reference. In this case all base validations (eg. number of allowed attributes) must be defined in the referenced schema.

.. code-block:: json

    {
        "definitions": {
            "person": {
                "$id": "#person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                }
            }
        },
        "$id": "#Citizen",
        "$ref": "#person"
    }

The same pattern works with ``$defs`` (Draft 2019-09):

.. code-block:: json

    {
        "$schema": "https://json-schema.org/draft/2019-09/schema",
        "$defs": {
            "person": {
                "$id": "#person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                }
            }
        },
        "$id": "#Citizen",
        "$ref": "#person"
    }

Generated interface:

.. code-block:: php

    // class Citizen
    public function setName(string $name): static;
    public function getName(): ?string;

If a base reference is used and the reference doesn't point to an object definition an Exception will be thrown during the model generation process:

* A referenced schema on base level must provide an object definition [Citizen]

A base reference must also not reference the schema's own root (``"$ref": ""`` or ``"$ref": "#"``
at base level) — an Exception will be thrown during the model generation process:

* A referenced schema on base level must not reference itself [Citizen]

$ref with sibling keywords
--------------------------

When additional keywords appear alongside ``$ref`` in the same schema object — such as
``properties``, ``required``, ``type``, ``minLength``, ``enum``, or others — their treatment
depends on the active JSON Schema draft.

**Draft 2019-09+ (siblings applied)**
    Sibling keywords take full effect alongside ``$ref``.  At the base/root level, sibling
    ``properties`` and ``required`` are merged with the referenced object's own properties —
    constraints from both sides apply (type intersection, required union).  At the property
    level, sibling constraints (``type``, ``minLength``, ``enum``, etc.) narrow the referenced
    type.

**Draft 7 (siblings silently ignored)**
    Sibling keywords are discarded.  Only the ``$ref`` is effective.  This matches the Draft 7
    specification, where ``$ref`` is exclusive over all co-located keywords.

    .. note::

        If your schemas rely on siblings alongside ``$ref`` and you switch from Draft 7 to
        Draft 2019-09 (or use ``AutoDetectionDraft`` with a ``$schema`` URI pointing to
        2019-09), those siblings will start taking effect.  Review schemas that combine
        ``$ref`` with extra keywords before upgrading.

**Example — base-level $ref + sibling properties (Draft 2019-09+)**

.. code-block:: json

    {
        "$schema": "https://json-schema.org/draft/2019-09/schema",
        "$defs": {
            "person": {
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    },
                    "age": {
                        "type": "integer"
                    }
                },
                "required": ["name"]
            }
        },
        "$ref": "#/$defs/person",
        "properties": {
            "street": {
                "type": "string"
            }
        },
        "required": ["name", "street"]
    }

The generated class exposes ``name`` and ``age`` (from the ref) together with ``street``
(from the sibling ``properties``):

.. code-block:: php

    public function getName(): ?string;
    public function getAge(): ?int;
    public function getStreet(): ?string;

When a property is declared by **both** the ``$ref``'d object and the sibling ``properties``
block, the two declarations are merged with ``allOf`` semantics: types narrow to their
intersection (for example, ``number`` ∩ ``integer`` → ``int``), narrowing never drops the
``$ref``'d side's own constraints (e.g. ``minimum``) — they're re-applied against the narrowed
type — and conflicting default values cause a generation-time ``SchemaException``. This merge
behavior is uniform across every ``$ref``+sibling position: base level, a property-level
``$ref`` to an object, and a property-level ``$ref`` to a scalar all merge the same way.

**Example — property-level $ref + sibling constraint (Draft 2019-09+)**

A property schema with ``$ref`` pointing to a string definition and a sibling ``minLength``:

.. code-block:: json

    {
        "$schema": "https://json-schema.org/draft/2019-09/schema",
        "type": "object",
        "properties": {
            "code": {
                "$ref": "#/$defs/shortString",
                "minLength": 5
            }
        },
        "$defs": {
            "shortString": {
                "type": "string",
                "minLength": 1
            }
        }
    }

Both constraints are enforced: the effective minimum length is ``5`` (the sibling tightens
the ref's ``minLength: 1``).
