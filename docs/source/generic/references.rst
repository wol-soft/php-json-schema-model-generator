References
==========

References can be used to re-use parts/objects of JSON-Schema definitions.

Supported reference types
-------------------------

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
        "$id": "team",
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

.. note::

    A ``$ref`` is a positive applicator. When an enclosing schema uses
    `unevaluatedProperties <../complexTypes/object.html#unevaluated-properties>`__ or
    `unevaluatedItems <../complexTypes/array.html#unevaluated-items>`__ (Draft 2019-09 and later),
    the resolved schema's evaluated set contributes to the enclosing schema's evaluated set —
    exactly as an inline branch of the same shape would. Self-referential ``$ref`` chains are
    handled without infinite recursion: the generator terminates the walk when it revisits a
    schema it has already processed.
