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

    public function setExample(array $example): static;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getExample(): ?array;

Possible exceptions:

* Invalid type for example. Requires array, got __TYPE__

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

    Invalid items in array example:
      - invalid item #3
        * Invalid type for item of array example. Requires string, got double

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

    Tuple array property contains invalid additional items.
      - invalid additional item '3'
        * Invalid type for name. Requires string, got integer
      - invalid additional item '5'
        * Invalid type for additional item. Requires object, got int

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

Unevaluated Items
-----------------

The ``unevaluatedItems`` keyword (Draft 2019-09 and later) constrains every index that was not
evaluated by any positive sibling applicator at the same schema level. Indices claimed by
``items`` (single-schema or tuple form), by ``additionalItems`` when present, by a passing
``contains`` match, or by a **successful** composition branch (``allOf``, ``anyOf``, ``oneOf``,
``if``/``then``/``else``, ``$ref``) count as evaluated. ``not`` is a negative applicator and
contributes nothing.

Unlike ``additionalItems``, ``unevaluatedItems`` looks across composition branches: an index
covered by a branch that ended up succeeding is credited, and an index covered only by a branch
that failed is not.

Using ``false``
^^^^^^^^^^^^^^^

Setting ``unevaluatedItems: false`` forbids any array index left uncovered by a sibling.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": [
                    {
                        "type": "string"
                    }
                ],
                "allOf": [
                    {
                        "items": [
                            {
                                "type": "string"
                            },
                            {
                                "type": "integer"
                            }
                        ]
                    }
                ],
                "unevaluatedItems": false
            }
        }
    }

Indices 0 and 1 are covered by the tuple items of the outer or the ``allOf`` branch. Any
further element raises an ``UnevaluatedItemsException`` with the offending indices:

.. code-block:: none

    Provided JSON for example contains not allowed unevaluated items [#2, #3]

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\UnevaluatedItemsException*
which provides the following methods to get further error details:

.. code-block:: php

    // Get the zero-based indices of the offending items
    public function getUnevaluatedItems(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

Using a schema
^^^^^^^^^^^^^^

When ``unevaluatedItems`` is set to a schema, every unevaluated index's value must validate
against that schema:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": [
                    {
                        "type": "string"
                    }
                ],
                "unevaluatedItems": {
                    "type": "integer"
                }
            }
        }
    }

If invalid unevaluated items are provided a detailed exception is thrown containing all
violations:

.. code-block:: none

    Invalid unevaluated items in array example:
      - invalid unevaluated item #1
        * Invalid type for item of array example. Requires int, got string

The thrown exception will be a *PHPModelGenerator\\Exception\\Arrays\\InvalidUnevaluatedItemsException*
which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by item index
    public function getInvalidItems(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer

Interaction with sibling applicators
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

When a sibling applicator already claims every index, the ``unevaluatedItems`` validator has
nothing to check. The generator recognises these dead-code shapes at generation time, emits a
warning, and skips ``unevaluatedItems`` entirely:

- ``items: false`` (or ``items: {schema}`` in single-schema form) leaves no unclaimed index at
  all, because ``items`` covers every position.
- ``additionalItems: false`` alongside tuple ``items`` blocks any index past the tuple length —
  every index is either tuple-covered (and evaluated) or rejected by ``additionalItems``.
- ``additionalItems: {schema}`` alongside tuple ``items`` claims every index past the tuple
  length — every index is again covered.
- An implicit ``additionalItems: false`` produced by the deny setting is treated the same way.

.. note::

    ``contains`` credits only the indices that actually satisfy its subschema, so any index not
    matched by ``contains`` remains available for ``unevaluatedItems``. When
    ``minContains: 0`` is set, ``contains`` may succeed with zero matches and contributes nothing
    to the evaluated set.

.. note::

    ``unevaluatedItems`` also accepts the boolean literal ``true``. This is a no-op — every index
    is considered evaluated — and no validator is emitted.

.. hint::

    See `combined schemas <../toc-combinedSchemas.html>`__ for the per-keyword rules that govern
    how each composition contributes to the evaluated set.

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

The thrown exception will be an *PHPModelGenerator\\Exception\\Arrays\\UniqueItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer
