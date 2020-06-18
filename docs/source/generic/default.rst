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
