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

Format
------

String formats are currently not supported
