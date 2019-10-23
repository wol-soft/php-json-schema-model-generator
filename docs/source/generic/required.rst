Required values
===============

By default the values of a schema are not required. In this case the input is valid if the property is not provided. Additionally an explicit `null` value is also valid. If the value isn't provided an optionally defined default value is used.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "default": "Not provided"
            }
        },
        "required": []
    }

Generated interface:

.. code-block:: php

    public function setExample(?string $example): self;
    public function getExample(): ?string;

Behaviour with different inputs:

.. code-block:: php

    // property example not provided
    $example = new Example([]);
    $example->getExample();     // returns "Not provided"

    // property example explicitly set to null (allowed as the property isn't required)
    $example = new Example(['example' => null]);
    $example->getExample();     // returns NULL

    // property example set to a custom value
    $example = new Example(['example' => 'My Input']);
    $example->getExample();     // returns "My Input"

By setting the property to a required value the property must be always provided.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        },
        "required": ["example"]
    }

Generated interface (typehints not nullable any longer):

.. code-block:: php

    public function setExample(string $example): self;
    public function getExample(): string;

Possible exceptions:

* Missing required value for example

Behaviour with different inputs:

.. code-block:: php

    // property example not provided --> throws an exception
    $example = new Example([]);

    // property example explicitly set to null --> throws an exception
    $example = new Example(['example' => null]);

    // property example set to a custom value
    $example = new Example(['example' => 'My Input']);
    $example->getExample();     // returns "My Input"
