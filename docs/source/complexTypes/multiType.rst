Multi Type
==========

By providing an array with types for a property multiple types can be allowed.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": ["number", "string"]
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(float | string $example): static;
    // the property isn't required, so null is included in the return type
    public function getExample(): float | string | null;

Possible exceptions:

* Invalid type for 'example': requires ['float', 'string'], got '__TYPE__'

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

Type arrays which declare a single type
---------------------------------------

A type array which permits exactly one type is equivalent to declaring that type directly, and is
handled as such - **["object"]** generates a class just like **"object"** does, and **["string"]**
is type hinted and validated like **"string"** (including the single type wording of the type
error, eg. *Invalid type for 'example': requires 'string', got '__TYPE__'*).

Duplicate entries are removed before that equivalence is applied, so **["string", "string"]** is
also handled like **"string"**.

An empty type array permits no value at all. As no input could ever satisfy such a property it is
rejected during the schema processing with a *PHPModelGenerator\\Exception\\SchemaException*.

Additional validators
---------------------

For each type given in the allowed types array additional validators may be added to the property:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": ["number", "string", "array"],
                "minimum": 10,
                "minLength": 4,
                "items": {
                    "type": "string"
                },
                "minItems": 2
            }
        }
    }

The property example will be type hinted with `float | string | string[]`.
The validators are applied if the given input matches the corresponding type.
For example if an array **["Hello", 123, "Goodbye"]** is given the validation will fail as numbers aren't allowed in arrays:

.. code-block:: none

    Invalid items in array 'example':
      - invalid item #1
        * Invalid type for 'example': requires 'string', got 'integer'