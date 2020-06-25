String
======

Used for properties containing characters. Converted to the PHP type `string`.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(?string $example): self;
    public function getExample(): ?string;

Possible exceptions:

* Invalid type for example. Requires string, got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Length validation
-----------------

To add a length validation to the property use the `minLength` and `maxLength` keywords. The length check is multi byte safe.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "minLength": 3,
                "maxLength": 5
            }
        }
    }

Possible exceptions:

* Value for example must not be shorter than 3
* Value for example must not be longer than 5

The thrown exception will be a *PHPModelGenerator\\Exception\\String\\MinLengthException* or a *PHPModelGenerator\\Exception\\String\\MaxLengthException* which provides the following methods to get further error details:

.. code-block:: php

    // for a MaxLengthException: get the maximum length of the string
    public function getMaximumLength(): int
    // for a MinLengthException: get the minimum length of the string
    public function getMinimumLength(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Pattern validation
------------------

To add a pattern validation to the property use `pattern` keyword.

.. warning::

    The validation is executed with `preg_match`, consequently PCRE syntax is used instead of ECMA 262.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "pattern": "^[a-zA-Z]*$"
            }
        }
    }

Possible exceptions:

* Value for property doesn't match pattern ^[a-zA-Z]*$

The thrown exception will be a *PHPModelGenerator\\Exception\\String\\PatternException* which provides the following methods to get further error details:

.. code-block:: php

    // get the expected pattern
    public function getExpectedPattern(): string
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Format
------

String formats are currently not supported.
