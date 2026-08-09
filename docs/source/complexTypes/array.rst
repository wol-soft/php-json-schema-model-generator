Array
=====

There are two types of arrays in JSON-Schema definitions: lists and tuples. Both types are supported by the model generator.

Lists
-----

A simple array without further restrictions can be defined using `array`.

.. code-block:: json

    {
        "title": "Example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(array $example): static;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getExample(): ?array;

Possible exceptions:

* Invalid type for 'example': requires 'array', got '__TYPE__'

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

Items
^^^^^

The items of a list can be restricted with a nested schema. All items of the schema must match the defined schema.

.. code-block:: json

    {
        "title": "Example",
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

    Invalid items in array 'example':
      - invalid item #3
        * Invalid type for 'example': requires 'string', got 'double'

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\InvalidItemException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by item index
    public function getInvalidItems(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

A more complex array may contain a nested object.

.. code-block:: json

    {
        "title": "Family",
        "type": "object",
        "properties": {
            "members": {
                "type": "array",
                "items": {
                    "type": "object",
                    "title": "Member",
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
    public function setMembers(array $members): static;
    public function getMembers(): ?array;

    // class Member
    public function setName(string $name): static;
    public function getName(): string;

    public function setAge(int $age): static;
    public function getAge(): ?int;

The *getMembers* function of the class *Family* is type hinted with *@returns Member[]*. Consequently auto completion is available when developing something like:

.. code-block:: php

    $family = new Family($inputArray);

    foreach ($family->getMembers() as $member) {
        // auto completion with available methods on $member
        $member->getName();
    }

.. hint::

    Arrays with item validation don't accept elements which contain `null`. If your array needs to accept `null` entries you have to add null to the type of your items explicitly (eg. "type": ["object", "null"]).

The ``items`` keyword also accepts the boolean literals ``true`` and ``false``.

``items: true`` — any array element is accepted; equivalent to not specifying ``items``.

``items: false`` — the array must be empty. Providing a non-empty array throws a ``MaxItemsException``
(Array X must not contain more than 0 items).

Tuples
------

A tuple array defines the structure of each array item on it's own. A tuple array is defined by providing an array of schemas with the `items` keyword.

Items
^^^^^

.. code-block:: json

    {
        "title": "Example",
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

    Invalid tuple item in array 'example':
      - invalid tuple #1
        * Invalid type for 'tuple item #1 of array example': requires 'string', got 'integer'
      - invalid tuple #1
        * Invalid type for 'name': requires 'string', got 'boolean'

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\InvalidTupleException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by item index
    public function getInvalidTuples()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

.. hint::

    Incomplete tuples are valid. Consequently an empty array provided for the schema shown above would pass the validation. Keep this in mind when designing tuple constraints. To force the given data to provide all tuples use tuple items combined with the `minItems` array size validation.

Additional items
^^^^^^^^^^^^^^^^

Using the keyword `additionalItems` the array can be limited to not contain any other value by providing `false`. If a schema is provided all additional items must be valid against the provided schema. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "title": "Example",
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
                    }
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

* Tuple array 'example' contains not allowed additional items: expected 2 items, got 3

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\AdditionalTupleItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // Get the expected tuple amount
    public function getExpectedAmount(): int
    // Get the amount of items provided
    public function getAmount(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

If invalid additional items are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Tuple array 'property' contains invalid additional items
      - invalid additional item '3'
        * Invalid type for 'name': requires 'string', got 'integer'
      - invalid additional item '5'
        * Invalid type for 'additional item': requires 'object', got 'integer'

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\InvalidAdditionalTupleItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by item index
    public function getNestedExceptions(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

Contains
--------

The contains check uses a schema which must match at least one of the items provided in the input data to pass the validation. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "title": "Example",
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

* No item in array 'example' matches the 'contains' constraint

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\ContainsException* which provides the following methods to get further error details:

.. code-block:: php

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

The ``contains`` keyword also accepts the boolean literals ``true`` and ``false``.

``contains: true`` — the array must contain at least one element (since ``true`` validates everything,
any element satisfies the constraint).

``contains: false`` — no element could ever satisfy the constraint; any array value raises a
``ContainsException`` at runtime. The generator also emits a warning at generation time.

.. note::

    ``minContains`` and ``maxContains`` require `Draft 2019-09 or later <../gettingStarted.html#json-schema-draft-version>`__.
    Under ``Draft_07`` (the default when no ``$schema`` is declared, or when ``$schema`` names a
    draft ``AutoDetectionDraft`` doesn't recognise) both keywords are silently ignored, and only a
    plain ``contains`` check (at least one match) is generated.

Number of matches (minContains / maxContains)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The keywords ``minContains`` and ``maxContains`` refine ``contains`` to require a minimum and/or
maximum number of items matching the ``contains`` schema, instead of just "at least one".

.. code-block:: json

    {
        "title": "Example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "contains": {
                    "type": "string"
                },
                "minContains": 2,
                "maxContains": 3
            }
        }
    }

Possible exceptions:

* Array 'example' must not contain less than 2 items matching the contains constraint, 1 matching items provided
* Array 'example' must not contain more than 3 items matching the contains constraint, 4 matching items provided

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\MinContainsException* or a
*PHPModelGenerator\\Exception\\Arrays\\MaxContainsException* which provides the following methods
to get further error details:

.. code-block:: php

    // for a MinContainsException: get the minimum amount of required matching items
    public function getMinContains(): int
    // for a MaxContainsException: get the maximum amount of allowed matching items
    public function getMaxContains(): int
    // get the amount of items which actually matched the contains constraint
    public function getMatches(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

.. hint::

    A ``minContains`` of ``0`` makes the plain ``contains`` check optional: an array with zero
    matching items is valid as long as ``maxContains`` (if set) isn't violated either.

Setting ``minContains`` to a negative number, or ``maxContains`` to a number smaller than ``1``, or
a ``minContains`` greater than ``maxContains``, is rejected with a ``SchemaException`` at
generation time.

Size validation
---------------

To limit the size of an array use the `minItems` and `maxItems` keywords.

.. code-block:: json

    {
        "title": "Example",
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

* Array 'example' must not contain less than 2 items
* Array 'example' must not contain more than 5 items

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\MaxItemsException* or a *PHPModelGenerator\\Exception\\Arrays\\MinItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // for a MaxItemsException: get the maximum amount of allowed items
    public function getMaxItems(): int
    // for a MinItemsException: get the minimum amount of required items
    public function getMinItems(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

Uniqueness
----------

The items of an array can be forced to be unique with the `uniqueItems` keyword.

.. code-block:: json

    {
        "title": "Example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "uniqueItems": true
            }
        }
    }

Possible exceptions:

* Items of array 'example' are not unique

The thrown exception will be an *PHPModelGenerator\\Exception\\Arrays\\UniqueItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer
