Merged Property
===============

If multiple subschemas are combined with `oneOf`, `anyOf` or `allOf` and the subschemas contain multiple nested objects all properties of the nested objects will be merged together in a single object.
For example we combine two objects with `allOf`:

.. code-block:: json

    {
        "$id": "company",
        "type": "object",
        "properties": {
            "ceo": {
                "$id": "CEO",
                "allOf": [
                    {
                        "type": "object",
                        "properties": {
                            "name": {
                                "type": "string"
                            }
                        }
                    },
                    {
                        "type": "object",
                        "properties": {
                            "age": {
                                "type": "integer"
                            }
                        }
                    }
                ]
            }
        }
    }

This schema will generate four classes. The main class will be `Company`, two classes to validate the subschemas combined with the `allOf` independent and one merged class containing all properties of the CEO (name and age in this example).
As the subschemas don't contain IDs they will be named with uniqIds (compare the `naming of classes <../complexTypes/object.html#naming>`__):

* Company.php
* Company_Ceo5e4a82e39edc3.php
* Company_Ceo5e4a82e39fe37.php
* Company_Merged_CEO.php

If the allOf doesn't contain an $id field the merged class will also contain an uniqId. So if you want to use the class with a reproducible class name you must set the $id field.
The classes Company_Ceo5e4a82e39edc3 and Company_Ceo5e4a82e39fe37 are only used for internal validation and can't be accessed via the generated interface of Company.

Generated interface:

.. code-block:: php

    # class Company
    public function setCeo(?Company_Merged_CEO $example): self;
    public function getCeo(): ?Company_Merged_CEO;

    # class Company_Merged_CEO
    public function getName(): ?string
    public function setName(?string $name): self
    public function getAge(): ?int
    public function setAge(?int $name): self
