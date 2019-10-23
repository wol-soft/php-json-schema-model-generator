Array
=====

There are two types of arrays in JSON-Schema definitions: lists and tuples. Both types are supported by the model generator.

Lists
-----

A simple array without further restrictions can be defined using `array`.

.. code-block:: json

    {
        "id": "example",
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
        "id": "example",
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

* Invalid item in array example

A more complex array may contain a nested object.

.. code-block:: json

    {
        "id": "example",
        "type": "family",
        "properties": {
            "members": {
                "type": "array",
                "items": {
                    "type": "object",
                    "id": "member",
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

TODO: documentation

Contains
--------

The contains check uses a schema which must match at least one of the items provided in the input data to pass the validation. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "id": "example",
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
        "id": "example",
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
        "id": "example",
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
