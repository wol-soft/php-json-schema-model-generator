Enum
====

Enums can be used to define a set of constant values a property must accept.

.. hint::

    If you define constraints via `enum` you may want to use the `EnumPostProcessor <../generator/builtin/enumPostProcessor.html>`__ to generate PHP enums.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "enum": ["ABC", "DEF"]
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(string $example): static;
    public function getExample(): ?string;

Possible exceptions:

* Invalid type for 'example': requires 'string', got '__TYPE__'
* Value for 'example' must be one of ["ABC","DEF"], got "GHI"

Untyped Enum
------------

An enum can also be defined without a specific type.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "enum": ["ABC", 10, true, null]
            }
        }
    }

Generated interface (no typehints are generated as it's a mixed untyped enum. If all values in the untyped enum are of the same type [eg. only strings] the generated interface will contain type hinting):

.. code-block:: php

    public function setExample($example): static;
    public function getExample();

Possible exceptions:

* Value for 'example' must be one of ["ABC",10,true,null], got "GHI"

Object and array values
-----------------------

The allowed values of an enum (and the value of a `const <../types/const.html>`__) may be objects and arrays. The provided value is compared with the allowed values as JSON data:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "$id": "person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    }
                },
                "enum": [
                    {
                        "name": "Albert"
                    },
                    {
                        "name": "Hannes"
                    }
                ]
            }
        }
    }

Generated interface (the property holds the model which is set up from the provided data):

.. code-block:: php

    public function setExample(?Person $example): static;
    public function getExample(): ?Person;

Possible exceptions:

* Value for 'example' must be one of [{"name":"Albert"},{"name":"Hannes"},null], got {"name":"Peter"}

The rules of the comparison:

* Arrays and objects are compared recursively. The keys of an object are compared independent of their order (``{"a": 1, "b": 2}`` equals ``{"b": 2, "a": 1}``), the items of an array must be provided in the order of the schema.
* A number inside of an array or an object equals a number of the other type with the same value (``1`` equals ``1.0``). A number which isn't part of an array or an object is compared as the type of the property: a property of the type *number* compares floats, a property of the type *integer* compares integers (also if the schema notes an allowed value as ``1.0``). The type of a property without a type is derived from the allowed values, an enum of integers doesn't accept the float ``2.0``.
* ``0``, ``false``, ``null``, ``""`` and ``"0"`` are different values.
* The value of an object property is the data which was provided for the object, not the generated model. A model which is provided to a setter is compared by the data it was set up with. The exception contains the data which was compared.
* The enum of the root of a schema file constrains the whole object. A model which was updated (setter, populate, accessors of additional properties) is validated against the enum with the state after the update.

The thrown exception will be an *PHPModelGenerator\\Exception\\Generic\\EnumException* which provides the following methods to get further error details:

.. code-block:: php

    // get all values which are allowed by the enum
    public function getAllowedValues(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer
