Default values
==============

Default values are set inside the model if a property which is not required isn't provided in the input data.

.. code-block:: json

    {
        "id": "example",
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
