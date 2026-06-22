Default values
==============

Default values are set inside the model if a property which is not required isn't provided in the input data.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "default": "Not provided"
            }
        }
    }

Behaviour with different inputs:

.. code-block:: php

    // property example not provided --> fallback to the default value
    $example = new Example([]);
    $example->getExample();     // returns "Not provided"

    // property example explicitly set to null (allowed as the property isn't required)
    $example = new Example(['example' => null]);
    $example->getExample();     // returns NULL

    // property example set to a custom value
    $example = new Example(['example' => 'My Input']);
    $example->getExample();     // returns "My Input"

.. hint::

    If no value for a property with a default value is defined the default value will be validated against all rules defined in the schema. Consequently you may get a validation error if the default value doesn't match your constraints.

    If you use a `filter <../nonStandardExtensions/filter.html>`__ on a property with a default value the default value will be filtered if no value is provided for the property. If the filter is a `transforming filter <../nonStandardExtensions/filter.html#transforming-filter>`__ the default value will be transformed.

Branch defaults in compositions
--------------------------------

Properties declared inside ``oneOf``, ``anyOf``, ``allOf``, ``if``/``then``/``else`` branches may
also carry a ``"default"`` value. The generator supports branch-level defaults and applies them
conditionally depending on the composition keyword.

**oneOf / anyOf / if–then–else**

For compositions where a single branch (or conditional branch) is active at a time, the branch
default is applied only when that branch is the active one. A user-supplied value always takes
precedence over the branch default.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "oneOf": [
            {
                "properties": {
                    "kind": {
                        "type": "string",
                        "enum": ["A"]
                    }
                },
                "required": ["kind"]
            },
            {
                "properties": {
                    "kind": {
                        "type": "string",
                        "enum": ["B"]
                    },
                    "timeout": {
                        "type": "integer",
                        "default": 30
                    }
                },
                "required": ["kind"]
            }
        ]
    }

.. code-block:: php

    // Branch B is active — timeout defaults to 30
    $example = new Example(['kind' => 'B']);
    $example->getTimeout();   // returns 30

    // Branch A is active — timeout has no default; returns null
    $example = new Example(['kind' => 'A']);
    $example->getTimeout();   // returns null

    // User-supplied value overrides the branch default
    $example = new Example(['kind' => 'B', 'timeout' => 60]);
    $example->getTimeout();   // returns 60

Branch defaults are **not** included in ``getRawModelDataInput()``. Only values explicitly
supplied by the caller appear in the raw input:

.. code-block:: php

    $example = new Example(['kind' => 'B']);
    $example->getRawModelDataInput();   // returns ['kind' => 'B']

**allOf**

For ``allOf`` compositions, all branches apply simultaneously. When multiple branches define a
default for the same property, the defaults must agree; a generation-time ``SchemaException`` is
thrown if they differ.

**Conflict detection**

The generator detects conflicting defaults at schema-processing time and throws a
``SchemaException`` when:

- Two ``oneOf`` or ``anyOf`` branches define the same property with different default values.
- A branch default disagrees with a default on the matching root ``properties`` entry.
- A ``patternProperties`` default disagrees with a branch or root default for the same property.
- Two ``patternProperties`` patterns both match the same named property and specify different
  defaults.

**patternProperties defaults**

A ``"default"`` value on a ``patternProperties`` entry propagates to every named property whose
key matches the pattern:

- If the named property is declared in the root ``properties`` section, it receives the default
  unconditionally — equivalent to placing the default directly on the property.
- If the named property exists only inside a composition branch, it receives the default
  conditionally — the same branch-default mechanism applies.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "retry_count": {
                "type": "integer"
            }
        },
        "patternProperties": {
            "^retry_": {
                "type": "integer",
                "minimum": 1,
                "default": 3
            }
        }
    }

.. code-block:: php

    // retry_count matches the pattern; default 3 is propagated
    $example = new Example([]);
    $example->getRetryCount();   // returns 3

    // User-supplied value overrides the pattern default
    $example = new Example(['retry_count' => 5]);
    $example->getRetryCount();   // returns 5
