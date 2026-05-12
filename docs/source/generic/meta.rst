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

$comment
--------

The ``$comment`` keyword is a developer-facing annotation. Its value is emitted as a paragraph
in the getter's PHPDoc and is not used for validation.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "$comment": "Internal note: this field maps to column user_name in the DB."
            }
        }
    }

Generated getter:

.. code-block:: php

    /**
     * Get the value of example.
     *
     * Internal note: this field maps to column user_name in the DB.
     *
     * @return string|null
     */
    public function getExample(): ?string;

examples
--------

The ``examples`` keyword provides one or more sample values for a property. Each entry is emitted
as an ``@example`` line in the getter's PHPDoc and is not used for validation.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "status": {
                "type": "string",
                "examples": ["active", "inactive", "pending"]
            }
        }
    }

Generated getter:

.. code-block:: php

    /**
     * Get the value of status.
     * @example active
     * @example inactive
     * @example pending
     *
     * @return string|null
     */
    public function getStatus(): ?string;

Non-string example values (numbers, booleans, arrays) are JSON-encoded in the annotation.

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
     * @param string $example My example property with a large and very helpful description
     *
     * @return self
     */
    public function setExample(string $example): static;
