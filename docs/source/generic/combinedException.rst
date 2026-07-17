Combined exceptions
===================

When building larger schema files with complex types like arrays or combined schemas exception messages may get larger as they include information about what violations were found.
Our example schema provides an array of people. The people object is combined via `allOf`:

.. code-block:: json

    {
        "definitions": {
            "name": {
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string",
                        "minLength": 2
                    }
                },
                "required": [
                    "name"
                ]
            }
        },
        "$id": "example",
        "type": "object",
        "properties": {
            "people": {
                "type": "array",
                "items": {
                    "allOf": [
                      {
                            "$ref": "#/definitions/name"
                        },
                        {
                            "type": "object",
                            "properties": {
                                "age": {
                                    "type": "integer"
                                }
                            }
                        }
                    ]
                }
            }
        }
    }

after generating the classes for the given schema we provide an array which contains only invalid items:

.. code-block:: php

    $example = new Example([
        ['name' => false, 'age' => 42],
        ['name' => 'F', 'age' => 'yes'],
        5,
        []
    ]);

The exception which will be thrown (combined array-exception and combined-schema-exception with indentation to indicate the error structure):

.. code-block:: none

    Invalid items in array 'property':
      - invalid item #0
        * Invalid value for 'property' declined by composition constraint
          Requires to match all composition elements but matched 1 elements
          - Composition element #1: Failed
            * Invalid type for 'name': requires 'string', got 'boolean'
          - Composition element #2: Valid
      - invalid item #1
        * Invalid value for 'property' declined by composition constraint
          Requires to match all composition elements but matched 0 elements
          - Composition element #1: Failed
            * Value for 'name' must not be shorter than 2
          - Composition element #2: Failed
            * Invalid type for 'age': requires 'int', got 'string'
      - invalid item #2
        * Invalid value for 'property' declined by composition constraint
          Requires to match all composition elements but matched 0 elements
          - Composition element #1: Failed
            * Invalid type for 'property': requires 'object', got 'integer'
          - Composition element #2: Failed
            * Invalid type for 'property': requires 'object', got 'integer'
      - invalid item #3
        * Invalid value for 'property' declined by composition constraint
          Requires to match all composition elements but matched 1 elements
          - Composition element #1: Failed
            * Missing required value for 'name'
            * Invalid type for 'name': requires 'string', got 'NULL'
          - Composition element #2: Valid

.. seealso::

    Each leaf ``ValidationException`` inside a combined exception also exposes a JSON pointer to the
    schema keyword that rejected the value. See
    `Collect errors vs. early return <../gettingStarted.html#collect-errors-vs-early-return>`__
    for details on ``getJsonPointer()``.