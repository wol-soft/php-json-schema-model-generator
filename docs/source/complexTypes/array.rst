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

.. hint::

    The items may be transformed by a filter (eg. ``dateTime``), also if they are declared inside a branch of a composition, as tuple items or ``unevaluatedItems`` or inside nested arrays (arrays which are items of an array). The ``unevaluatedItems`` of a nested array can't be filtered if serialization is enabled. The array then contains the transformed items. See `Filters on array items <../nonStandardExtensions/filter.html#filters-on-array-items>`__.

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

If invalid tuples are provided a detailed exception will be thrown containing all violations. The tuples are numbered starting with 1, the items are named by their index starting with 0. For the schema shown above the input ``[5, {"name": true}]`` results in:

.. code-block:: none

    Invalid tuple item in array 'example':
      - invalid tuple #1
        * Invalid type for 'tuple item #0 of array example': requires 'string', got 'integer'
      - invalid tuple #2
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

A tuple item also accepts the boolean literals ``true`` and ``false`` instead of a schema:

``true`` — any value (also ``null``) is accepted at the index, the other items keep being validated.

``false`` — no value is accepted at the index. An array which is shorter than the index is valid.

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
                    },
                    false
                ]
            }
        }
    }

If a value is provided at the index of a ``false`` item the exception contains a *PHPModelGenerator\\Exception\\Generic\\DeniedPropertyException* for the item (``['a', 1]`` provided for the schema shown above):

.. code-block:: none

    Invalid tuple item in array 'example':
      - invalid tuple #2
        * Value for 'tuple item #1 of array example' is not allowed

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

If invalid additional items are provided a detailed exception will be thrown containing all violations. The additional items are named by their index in the array. For the schema shown above the input ``["ab", 1, {"name": "a"}, {"name": 5}, {"name": "b"}, 7]`` results in:

.. code-block:: none

    Tuple array 'example' contains invalid additional items
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

If ``additionalItems`` is ``false`` an array which contains more items than the tuple defines is rejected:

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
                    }
                ],
                "additionalItems": false
            }
        }
    }

Possible exceptions (``["ab", 1, "extra"]`` provided):

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

Recursive tuples
^^^^^^^^^^^^^^^^

A tuple item may reference the array which owns the tuple, eg. to describe a linked list or a tree written as ``[value, next]``. Each level of the nesting is validated against the tuple:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "definitions": {
            "node": {
                "type": "array",
                "items": [
                    {
                        "type": "string"
                    },
                    {
                        "$ref": "#/definitions/node"
                    }
                ]
            }
        },
        "properties": {
            "tree": {
                "$ref": "#/definitions/node"
            }
        }
    }

.. code-block:: php

    $example = new Example(['tree' => ['a', ['b', ['c']]]]);
    // ['a', ['b', ['c']]]
    $example->getTree();

An invalid value on a deeper level is reported by the *InvalidTupleException* of each level above it. The exception of a level is nested into the exception of the level which contains it. For the schema shown above the input ``{"tree": ["a", [1]]}`` results in:

.. code-block:: none

    Invalid tuple item in array 'tree':
      - invalid tuple #2
        * Invalid tuple item in array 'tuple item #1 of array tree':
          - invalid tuple #1
            * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'

The tuple items may be transformed by filters (see `Filters on array items <../nonStandardExtensions/filter.html#filters-on-array-items>`__), also on every level of a recursive tuple.

The recursion may also run over the schema of ``additionalItems``, ``unevaluatedItems`` and ``contains``, see `Recursive array keywords`_.

Contains
--------

The contains check uses a schema which must match at least one of the items provided in the input data to pass the validation. Simple checks like 'must contain a string' are possible as well as checks like 'must contain an object with a specific structure'. The schema is a regular schema, also tuples, items, compositions and conditions can be used.

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
that failed is not. Compositions nested inside a branch contribute their own successful branches
the same way, to any depth.

A property subschema that declares array applicators but omits ``type: array`` is treated as an
array property: the keywords apply whenever the provided value is an array, and a value of any
other type passes through unchanged.

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

    Provided JSON for 'example' contains not allowed unevaluated items [#2, #3]

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

    Invalid unevaluated items in array 'example':
      - invalid unevaluated item #1
        * Invalid type for 'unevaluated item': requires 'int', got 'string'

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

- ``items: false`` rejects every index, so the array is forced empty.
- ``items: true``, ``items: {}`` or ``items: {schema}`` in single-schema form leave no unclaimed
  index at all, because ``items`` covers every position.
- ``additionalItems: false`` alongside tuple ``items`` blocks any index past the tuple length —
  every index is either tuple-covered (and evaluated) or rejected by ``additionalItems``.
- ``additionalItems: true`` or ``additionalItems: {schema}`` alongside tuple ``items`` accepts
  and evaluates every index past the tuple length (a schema additionally validates it) — every
  index is again claimed. A tail value is accepted or rejected by ``additionalItems`` alone; a
  skipped ``unevaluatedItems`` schema never takes part in the decision.

Unlike ``additionalProperties`` on objects, the
`deny additional properties setting <../gettingStarted.html#deny-additional-properties>`__ has no
effect on arrays: an index past a tuple stays subject to ``unevaluatedItems``.

Nested arrays
^^^^^^^^^^^^^

``unevaluatedItems`` may be declared for any array, also for an array which is an item of another
array (``items``, a tuple index, ``additionalItems``, ``contains`` or ``unevaluatedItems`` of the outer
array) or the value of an additional, pattern or unevaluated property of an object. Each of these
arrays is evaluated on its own: the indices which are evaluated in one inner array (by its tuple,
its ``contains``, its composition branches or by ``unevaluatedItems`` itself) are never evaluated in
another inner array or in the outer array.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "array",
                "items": {
                    "type": "array",
                    "items": [
                        {
                            "type": "integer"
                        }
                    ],
                    "unevaluatedItems": {
                        "type": "string"
                    }
                }
            }
        }
    }

Valid is ``[[1, "a"], [2]]`` and ``[[1, "a", "b"], [2, "c"]]``, whereas ``[[1, "a", "b"], [2, 3]]`` is
invalid: the integer ``3`` at the index 1 of the second inner array is not evaluated by the tuple of
that array, the strings evaluated in the first inner array don't count. The errors of the inner arrays are
reported by the exception of the outer array:

.. code-block:: none

    Invalid items in array 'example':
      - invalid item #1
        * Invalid unevaluated items in array 'example':
          - invalid unevaluated item #1
            * Invalid type for 'unevaluated item': requires 'string', got 'integer'

The elements of an array which are objects are validated by a class of their own which evaluates
the keyword inside the object.

.. warning::

    A transforming filter inside the ``unevaluatedItems`` of a nested array can't be serialized and
    is rejected at generation time if serialization is enabled, see
    `Filters on array items <../nonStandardExtensions/filter.html#filters-on-array-items>`__.

.. note::

    ``contains`` credits only the indices that actually satisfy its subschema, so any index not
    matched by ``contains`` remains available for ``unevaluatedItems``. When
    ``minContains: 0`` is set, ``contains`` may succeed with zero matches and contributes nothing
    to the evaluated set.

.. note::

    ``unevaluatedItems`` also accepts the boolean literal ``true``. On its own this is a no-op —
    every index is considered evaluated — and no validator is emitted. Inside a composition branch
    an explicit ``unevaluatedItems: true`` (like ``items: true`` or ``items: {}``) annotates every
    index of the array, so a successful branch credits all of them to an enclosing
    ``unevaluatedItems``. An *omitted* keyword produces no annotation.

.. hint::

    See `combined schemas <../toc-combinedSchemas.html>`__ for the per-keyword rules that govern
    how each composition contributes to the evaluated set.

Recursive array keywords
------------------------

Not only a tuple item may reference the array which owns the keyword: the schema of ``additionalItems``, of ``unevaluatedItems`` (Draft 2019-09 and later) and of ``contains`` may do so as well. Each level of the nesting is validated by the keyword of the array of the level. The definitions may reference each other, and the keywords may be mixed in a cycle (eg. the additional items of a list are described by a definition whose ``contains`` references the list).

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "definitions": {
            "node": {
                "type": "array",
                "items": [
                    {
                        "type": "string"
                    }
                ],
                "additionalItems": {
                    "$ref": "#/definitions/node"
                }
            }
        },
        "properties": {
            "tree": {
                "$ref": "#/definitions/node"
            }
        }
    }

.. code-block:: php

    $example = new Example(['tree' => ['a', ['b', ['c']], []]]);
    // ['a', ['b', ['c']], []]
    $example->getTree();

An invalid value on a deeper level is reported by the exception of each level above it. For the schema shown above the input ``{"tree": ["a", ["b", 1]]}`` results in:

.. code-block:: none

    Tuple array 'tree' contains invalid additional items
      - invalid additional item '1'
        * Tuple array 'additional item' contains invalid additional items
          - invalid additional item '1'
            * Invalid type for 'additional item': requires 'array', got 'integer'

The same applies to ``unevaluatedItems`` (the exceptions are *InvalidUnevaluatedItemsException*, the nested array is named ``unevaluated item``).

A recursive tuple may declare ``unevaluatedItems`` for the array of each level (``"unevaluatedItems": false`` or a schema). The indices a level evaluates (by its tuple or by the branches of a composition of the level) never count for another level, so the surplus items of each level are rejected or validated by the keyword of that level.

A recursion over ``contains`` needs a schema which is satisfied without an item matching the schema itself, as the array of a level matches only if one of its items matches. The following node is a string or an array which contains a node:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "definitions": {
            "node": {
                "type": [
                    "string",
                    "array"
                ],
                "contains": {
                    "$ref": "#/definitions/node"
                }
            }
        },
        "properties": {
            "tree": {
                "$ref": "#/definitions/node"
            }
        }
    }

The values ``"a"``, ``["a"]``, ``[1, ["a"]]`` and ``[[["a"]]]`` are valid. The input ``{"tree": [1, [2]]}`` is rejected, the array ``[2]`` contains no node and so doesn't match the schema of the outer ``contains``. The exception only reports that no item matches:

.. code-block:: none

    No item in array 'tree' matches the 'contains' constraint

The branches of the compositions ``allOf``, ``anyOf``, ``oneOf``, ``not`` and ``if``/``then``/``else`` may validate the items against the array which owns the composition as well. The following node is valid unless it holds items and every one of them is a valid node itself:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "definitions": {
            "node": {
                "type": "array",
                "not": {
                    "items": {
                        "$ref": "#/definitions/node"
                    },
                    "minItems": 1
                }
            }
        },
        "properties": {
            "tree": {
                "$ref": "#/definitions/node"
            }
        },
        "required": [
            "tree"
        ]
    }

The values ``[]`` and ``[[[]]]`` are valid, the validity alternates with the depth of the nesting. The input ``{"tree": [[]]}`` is rejected, as the only item ``[]`` is a valid node:

.. code-block:: none

    Invalid value for 'tree' declined by composition constraint
      Requires to match none composition element but matched 1 element
      - Composition element #1: Valid

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

* Items of array 'example' are not unique

The thrown exception will be an *PHPModelGenerator\\Exception\\Arrays\\UniqueItemsException* which provides the following methods to get further error details:

.. code-block:: php

    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
    // get the JSON pointer to the schema keyword that rejected the value
    public function getJsonPointer(): JsonPointer
