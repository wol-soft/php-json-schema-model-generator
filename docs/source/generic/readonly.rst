Readonly and write-only values
==============================

readOnly
--------

By default all classes are immutable. If the GeneratorConfiguration option for immutability is disabled setters for all properties are generated. If single properties should be readonly the keyword ``readOnly`` can be used.

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "readOnly": true
            },
            "age": {
                "type": "integer"
            }
        }
    }

Generated interface (with immutability disabled):

.. code-block:: php

    public function getName(): ?string;

    public function setAge(int $example): static;
    public function getAge(): ?int;

writeOnly
---------

Properties marked with ``writeOnly: true`` suppress getter generation. The property can be set (via constructor or setter) but its value cannot be read back from the model. This is the inverse of ``readOnly``.

.. code-block:: json

    {
        "$id": "credentials",
        "type": "object",
        "properties": {
            "username": {
                "type": "string"
            },
            "password": {
                "type": "string",
                "writeOnly": true
            }
        }
    }

Generated interface (with immutability disabled):

.. code-block:: php

    public function getUsername(): ?string;
    public function setUsername(?string $username): static;

    // no getPassword() — write-only property
    public function setPassword(?string $password): static;

When serialization is enabled (``SerializationPostProcessor``), ``writeOnly`` properties are automatically excluded from ``toArray()`` and ``jsonSerialize()`` output, as they are considered input-only data that must not be exposed.

Combining ``readOnly: true`` and ``writeOnly: true`` on the same property is a schema error and will throw a ``SchemaException`` at generation time.
