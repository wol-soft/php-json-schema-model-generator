Readonly values
===============

By default all classes are immutable. If the GeneratorConfiguration option for immutability is disabled setters for all properties are generated. If single properties should be readonly the keyword `readonly` can be used.

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "readonly": true
            },
            "age": {
                "type": "integer"
            }
        }
    }

Generated interface (with immutability disabled):

.. code-block:: php

    public function getName(): ?string;

    public function setAge(?int $example): self;
    public function getAge(): ?int;
