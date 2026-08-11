Enum
====

Enums can be used to define a set of constant values a property must accept.

.. hint::

    If you define constraints via `enum` you may want to use the `EnumPostProcessor <../generator/builtin/enumPostProcessor.html>`__ to generate PHP enums.

.. code-block:: json

    {
        "title": "Example",
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

.. note::

    If ``enum`` lists more than 8 allowed values, the exception message truncates the list to the
    first 5 values followed by ``, ... (and N more)`` instead of printing every value (e.g.
    ``Value for 'example' must be one of ["A","B","C","D","E", ... (and 3 more)], got "X"``).
    ``getAllowedValues()`` on the exception always returns the complete, untruncated list.

Untyped Enum
------------

An enum can also be defined without a specific type.

.. code-block:: json

    {
        "title": "Example",
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
