Meta data
=========

ID
--

The ID of a schema is used to generate the class name. If no ID is present the filename of the JSON-Schema file will be used as class name

.. code-block:: json

    {
        "$id": "MyObject",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
            }
        }
    }

The generated class will be **MyObject** in *MyObject.php*

Description
-----------

If a property provides a description this description will be adopted into the generated PHP class so the documentation is available.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "description": "My example property with a large and very helpful description"
            }
        }
    }

Generated code in the PHP class:

.. code-block:: php

    /** @var string My example property with a large and very helpful description */
    protected $example;

    ...

    /**
     * Get the value of example.
     *
     * My example property with a large and very helpful description
     *
     * @returns string|null
     */
    public function getExample(): ?string;

    /**
     * Set the value of example.
     *
     * @param string|null $example My example property with a large and very helpful description
     *
     * @return self
     */
    public function setExample(?string $example): self;
