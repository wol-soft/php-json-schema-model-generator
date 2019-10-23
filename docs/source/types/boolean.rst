Boolean
=======

Used for properties containing boolean values. Converted to the PHP type `bool`.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "boolean"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(?bool $example): self;
    public function getExample(): ?bool;

Possible exceptions:

* Invalid type for example. Requires bool, got __TYPE__
