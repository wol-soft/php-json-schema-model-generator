References
==========

References can be used to re-use parts/objects of JSON-Schema definitions.

Supported reference types
-------------------------

* internal (in a single file) reference by id (example: `"$ref": "#IdOfMyObject"`)
* internal (in a single file) reference by path (example: `"$ref": "#/definitions/myObject"`)
* relative reference based on the location on the file system to a complete file (example: `"$ref": "./../modules/myObject.json"`)
* relative reference based on the location on the file system to an object by id (example: `"$ref": "./../modules/myObject.json#IdOfMyObject"`)
* relative reference based on the location on the file system to an object by path (example: `"$ref": "./../modules/myObject.json#/definitions/myObject"`)
* absolute reference based on the location on the file system to a complete file (example: `"$ref": "/modules/myObject.json"`)
* absolute reference based on the location on the file system to an object by id (example: `"$ref": "/modules/myObject.json#IdOfMyObject"`)
* absolute reference based on the location on the file system to an object by path (example: `"$ref": "/modules/myObject.json#/definitions/myObject"`)
* network reference to a complete file (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json"`)
* network reference to an object by id (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json#IdOfMyObject"`)
* network reference to an object by path (example: `"$ref": "https://my.domain.com/schema/modules/myObject.json#/definitions/myObject"`)

If an `$id` is present in the schema, the `$ref` will be resolved relative to the `$id` (except the `$ref` already is an absolute reference, e.g. a full URL).
The behaviour of `$ref` resolving can be overwritten by implementing a custom **SchemaProviderInterface**, for example when you want to use network references behind an authorization.

.. note::

    For absolute local references, the default implementation traverses up the directory tree until it finds a matching file to find the project root

Object reference
----------------

An example for properties referring to a definition inside the same schema:

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
        "$id": "team",
        "type": "object",
        "properties": {
            "leader": {
                "$ref": "#person"
            }
            "members": {
                "type": "array",
                "items": {
                    "$ref": "#/definitions/person"
                }
            }
        }
    }

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

Generated interface:

.. code-block:: php

    // class Citizen
    public function setName(string $name): static;
    public function getName(): ?string;

If a base reference is used and the reference doesn't point to an object definition an Exception will be thrown during the model generation process:

* A referenced schema on base level must provide an object definition [Citizen]

External file deduplication
----------------------------

When multiple schemas in the same provider directory reference the same external file via ``$ref``,
the generator processes that file only once and reuses the resulting class for every reference site.
The canonical class name is derived from the referenced filename — for example, a ``$ref`` to
``address.json`` resolves to the ``Address`` class, regardless of which schema first triggers the
resolution and regardless of the order in which the provider iterates the files.

This means that all type hints across all referencing schemas point to the same ``Address`` class,
so ``instanceof`` checks, serialisation, and builder post-processors all see a consistent type.

Files outside the provider's base directory (e.g. referenced via ``../`` paths) are handled via
an internal placeholder and are not rendered as standalone classes; they still resolve correctly
for fragment references (``file.json#/definitions/Foo``).
