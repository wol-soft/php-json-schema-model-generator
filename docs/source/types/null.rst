Null
====

Used for properties which only accept `null`.

.. code-block:: json

    {
        "id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "null"
            }
        }
    }

Generated interface (as null is no explicit type no typehints are generated):

.. code-block:: php

    public function setExample($example): self;
    public function getExample();

Possible exceptions:

* Invalid type for property. Requires null, got __TYPE__
