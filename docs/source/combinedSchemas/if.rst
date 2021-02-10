If
==

The keywords `if`, `then` and `else` can be used to conditionally combine multiple subschemas. Conditional compositions can be used on property level and on object level.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "number",
                "if": {
                    "multipleOf": 5
                },
                "then": {
                    "minimum": 100
                },
                "else": {
                    "maximum": 100
                }
            }
        }
    }

Valid values are eg. 100, 105, 99. Invalid values are eg. 50, 101 or any non numeric values.

Generated interface:

.. code-block:: php

    public function setExample(float $example): self;
    public function getExample(): ?float;

Possible exception (in this case 50 was provided so the if condition succeeds but the then branch failed):

.. code-block:: none

    Invalid value for example declined by conditional composition constraint
      - Condition: Valid
      - Conditional branch failed:
        * Value for example must not be smaller than 100

Another example exception with 101 as value for the property:

.. code-block:: none

    Invalid value for example declined by conditional composition constraint
      - Condition: Failed
        * Value for example must be a multiple of 5
      - Conditional branch failed:
        * Value for example must not be larger than 100

The thrown exception will be a *PHPModelGenerator\\Exception\\ComposedValue\\ConditionalException* which provides the following methods to get further error details:

.. code-block:: php

    // get the exception which triggered the condition to fail
    // if error collection is enabled an ErrorRegistryException will be returned
    public function getIfException(): ?Exception

    // get the exception which triggered the conditional branch to fail
    // if error collection is enabled an ErrorRegistryException will be returned
    public function getThenException(): ?Exception
    public function getElseException(): ?Exception

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

An object level composition will result in an object which contains all properties contained in the three possible blocks of the condition.

.. code-block:: json

    {
        "$id": "customer",
        "type": "object",
        "properties": {
            "country": {
                "enum": ["United States of America", "Canada"]
            }
        },
        "if": {
            "type": "object",
            "properties": {
                "country": {
                    "const": "United States of America"
                }
            }
        },
        "then": {
            "type": "object",
            "properties": {
                "postal_code": {
                    "pattern": "[0-9]{5}(-[0-9]{4})?"
                }
            }
        },
        "else": {
            "type": "object",
            "properties": {
                "postal_code": {
                    "pattern": "[A-Z][0-9][A-Z] [0-9][A-Z][0-9]"
                }
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setCountry(string $country): self;
    public function getCountry(): ?string;

    public function setPostalCode(string $country): self;
    public function getPostalCode(): ?string;
