Null
====

Used for properties which only accept `null`.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "null"
            }
        }
    }

Generated interface (as null is no explicit type no typehints are generated):

.. code-block:: php

    public function setExample($example): static;
    public function getExample();

Possible exceptions:

* Invalid type for 'example': requires 'null', got '__TYPE__'

The main use case for the **null** type is a property with `multiple types <complexTypes/multiType.html>`__ accepting for example a string and null values when using explicit null types.

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

Null only properties via const and enum
---------------------------------------

A property declared with `"const": null` or `"enum": [null]` only accepts `null`, too. Contrary to the **null** type the typehint is auto-detected from the value. The declared type is `null` independent of the property being required or optional and of the implicit null setting.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "const": null
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(null $example): static;
    public function getExample(): null;

Possible exceptions:

* Value for 'example' must be null, got "text" (for `const`)
* Value for 'example' must be one of [null], got "text" (for `enum`)
