Array
=====

There are two types of arrays in JSON-Schema definitions: lists and tuples. Both types are supported by the model generator.

Lists
-----

A simple array without further restrictions can be defined using `array`.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(?array $example): self;
    public function getExample(): ?array;

Possible exceptions:

* Invalid type for example. Requires array, got __TYPE__

Items
^^^^^

The items of a list can be restricted with a nested schema. All items of the schema must match the defined schema.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": {
                    "type": "string",
                    "minLength": 2
                }
            }
        }
    }

With a schema like this all items must contain a string with at least two characters. Possible exceptions:

.. code-block:: none

    Invalid item in array example:
      - invalid item #3
        * Invalid type for item of array example. Requires string, got double

A more complex array may contain a nested object.

.. code-block:: json

    {
        "$id": "example",
        "type": "family",
        "properties": {
            "members": {
                "type": "array",
                "items": {
                    "type": "object",
                    "$id": "member",
                    "properties": {
                        "name": {
                            "type": "string"
                        },
                        "age": {
                            "type": "integer",
                            "minimum": 0
                        }
                    },
                    "required": [
                        "name"
                    ]
                }
            }
        }
    }

In this case the model generator will generate two classes: **Family** and **Member**. Generated interfaces:

.. code-block:: php

    // class Family
    public function setMembers(?array $members): self;
    public function getMembers(): ?array;

    // class Member
    public function setName(string $name): self;
    public function getName(): string;

    public function setAge(?int $age): self;
    public function getAge(): ?int;

The *getMembers* function of the class *Family* is typehinted with *@returns Member[]*. Consequently auto completion is available when developing something like:

.. code-block:: php

    $family = new Family($inputArray);

    foreach ($family->getMembers() as $member) {
        // auto completion with available methods on $member
        $member->getName();
    }

Tuples
------

A tuple array defines the structure of each array item on it's own. A tuple array is defined by providing an array of schemas with the `items` keyword.

Items
^^^^^

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": [
                    {
                        "type": "string",
                        "minLength": 2
                    },
                    {
                        "type": "object",
                        "properties": {
                            "name": {
                                "type": "string"
                            }
                        }
                    }
                ]
            }
        }
    }

If invalid tuples are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Invalid tuple item in array example:
      - invalid tuple #1
        * Invalid type for tuple item #1 of array example. Requires string, got int
      - invalid tuple #1
        * Invalid type for name. Requires string, got boolean

.. hint::

    Incomplete tuples are valid. Consequently an empty array provided for the schema shown above would pass the validation. Keep this in mind when designing tuple constraints. To force the given data to provide all tuples use tuple items combined with the `minItems` array size validation.

Additional items
^^^^^^^^^^^^^^^^

Using the keyword `additionalItems` the array can be limited to not contain any other value by providing `false`. If a schema is provided all additional items must be valid against the provided schema. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": [
                    {
                        "type": "string",
                        "minLength": 2
                    },
                    {
                        "type": "integer"
                    },
                ],
                "additionalItems": {
                    "type": "object",
                    "properties": {
                        "name": {
                            "type": "string"
                        }
                    }
                }
            }
        }
    }

Possible exceptions:

* Tuple array example contains not allowed additional items. Expected 2 items, got 3

If invalid additional items are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Tuple array property contains invalid additional items.
      - invalid additional item '3'
        * Invalid type for name. Requires string, got integer
      - invalid additional item '5'
        * Invalid type for additional item. Requires object, got int

Contains
--------

The contains check uses a schema which must match at least one of the items provided in the input data to pass the validation. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "contains": {
                    "type": "string"
                }
            }
        }
    }

Possible exceptions:

* No item in array example matches contains constraint

Size validation
---------------

To limit the size of an array use the `minItems` and `maxItems` keywords.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "minItems": 2,
                "maxItems": 5
            }
        }
    }

Possible exceptions:

* Array example must not contain less than 2 items
* Array example must not contain more than 5 items

Uniqueness
----------

The items of an array can be forced to be unique with the `uniqueItems` keyword.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "uniqueItems": true
            }
        }
    }

Possible exceptions:

* Items of array example are not unique
