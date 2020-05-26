Object
======

Properties which contain an object will result in an additional PHP class. The PHP classes will be connected via type hinting to provide autocompletion during the development.

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string"
            },
            "car": {
                "$id": "car",
                "type": "object",
                "properties": {
                    "model": {
                        "type": "string"
                    },
                    "ps": {
                        "type": "integer"
                    }
                }
            }
        }
    }

Generated interface:

.. code-block:: php

    // class Person
    public function setName(?string $name): self;
    public function getName(): ?string;
    public function setCar(?Car $name): self;
    public function getCar(): ?Car;

    // class Person_Car
    public function setModel(?string $name): self;
    public function getModel(): ?string;
    public function setPs(?int $name): self;
    public function getPs(): ?int;

Possible exceptions:

* Invalid type for car. Requires object, got __TYPE__

The nested object will be validated in the nested class Car which may throw additional exceptions if invalid data is provided.

Namespaces
----------

If a nested class is generated the nested class will be located in the same namespace as the parent class.
If the nested class occurs somewhere else and has already been generated a class from another namespace may be used (compare `namespaces <../generic/namespaces.html>`__ for additional information concerning class re-usage).

Naming
------

Naming of classes
^^^^^^^^^^^^^^^^^

If the given main object in a JSON-Schema file contains a `$id` the id will be used as class name. Otherwise the name of the file will be used.

Naming of nested classes
^^^^^^^^^^^^^^^^^^^^^^^^

Nested classes are prefixed with the parent class. If an object `Person` has a nested object `car` the class for car will be named **Person_Car**.

For the class name of a nested class the `$id` property of the nested object is used. If the id property isn't present the property key combined with a uniqid will be used.

Property Name Normalization
^^^^^^^^^^^^^^^^^^^^^^^^^^^

Property names are normalized to provide valid and readable PHP code. All non alpha numeric characters will be removed.

.. code-block:: json

    {
        "type": "object",
        "properties": {
            "underscore_property-minus": {
                "type": "string"
            },
            "CAPS and space 100": {
                "type": "string"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setUnderscorePropertyMinus(?string $name): self;
    public function getUnderscorePropertyMinus(): ?string;
    public function setCapsAndSpace100(?string $name): self;
    public function getCapsAndSpace100(): ?string;

If the name normalization results in an empty attribute name (eg. '__ -- __') an exception will be thrown.

Required properties
-------------------

Using the keyword `required` a list of properties may be defined which must be provided.


.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string"
            },
            "age": {
                "type": "integer"
            }
        },
        "required": [
            "name"
        ]
    }

Possible exceptions:

* Missing required value for name

.. warning::

    Properties defined in the `required` array but not defined in the `properties` section of the object aren't validated. Consequently provided objects missing these fields may be considered valid.

Size
----

With the keywords `minProperties` and `maxProperties` the number of allowed properties can be limited:

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string"
            }
        },
        "minProperties": 2,
        "maxProperties": 3
    }

Possible exceptions:

* Provided object for person must not contain less than 2 properties
* Provided object for person must not contain more than 3 properties

Additional Properties
---------------------

Using the keyword `additionalProperties` the object can be limited to not contain any additional properties by providing `false`. If a schema is provided all additional properties must be valid against the provided schema. Simple checks like 'must provide a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "integer"
            }
        },
        "additionalProperties": {
            "type": "object",
            "properties": {
                "name": {
                    "type": "string"
                },
                "age": {
                    "type": "integer"
                }
            }
        }
    }

Possible exceptions:

* Provided JSON contains not allowed additional properties [additional1, additional2]

If invalid additional properties are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Provided JSON contains invalid additional properties.
      - invalid additional property 'additional1'
        * Invalid type for name. Requires string, got integer
      - invalid additional property 'additional2'
        * Invalid type for age. Requires int, got string

Recursive Objects
-----------------

If objects are defined recursive the recursion will be resolved into a single class.

.. code-block:: json

    {
        "definitions": {
            "person": {
                "$id": "person",
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    },
                    "children": {
                        "type": "array",
                        "items": {
                            "$ref": "#/definitions/person"
                        }
                    }
                }
            }
        },
        "$id": "family",
        "type": "object",
        "properties": {
            "members": {
                "type": "array",
                "items": {
                    "$ref": "#/definitions/person"
                }
            }
        }
    }

Generated interface:

.. code-block:: php

    // class Family, arrays typehinted in docblocks with Family_Person[]
    public function setMembers(?array $members): self;
    public function getMembers(): ?array;

    // class Person, arrays typehinted in docblocks with Family_Person[]
    public function setName(?string $name): self;
    public function getName(): ?string;
    public function setChildren(?array $name): self;
    public function getChildren(): ?array;

Property Names
--------------

With the keyword `propertyNames` rules can be defined which must be fulfilled by each given property.

.. code-block:: json

    {
        "type": "object",
        "propertyNames": {
            "pattern": "^test[0-9]+$",
            "maxLength": 8
        }
    }

Compare `strings <../types/string.html>`__ for information concerning possible property name validators.

Exceptions contain detailed information about the violations:

.. code-block:: none

    Provided JSON contains properties with invalid names.
      - invalid property 'test12345a'
        * Value for property name doesn't match pattern ^test[0-9]+$
        * Value for property name must not be longer than 8
      - invalid property 'test123456789'
        * Value for property name must not be longer than 8

Dependencies
------------

With the keyword `dependencies` a list of properties can be defined which require a given dependency to be fulfilled if the property is present.

Property Dependencies
^^^^^^^^^^^^^^^^^^^^^

Property dependencies refer to a list of other object properties. Each of the referred property is required if the property utilizing the dependency is present.

.. code-block:: json

    {
        "type": "object",
        "properties": {
            "credit_card": {
                "type": "integer"
            },
            "billing_address": {
                "type": "string"
            }
        },
        "dependencies": {
            "credit_card": [
                "billing_address"
            ]
        }
    }

The generated object accepts input which provide none of the defined properties, both of the defined properties or only the billing_address. If only a credit_card is provided the validation will fail as the presence of the credit_card property depends on the presence of the billing_address.

Exceptions contain a list of all violated properties which are declared as a dependency but aren't provided:

.. code-block:: none

    Missing required attributes which are dependants of credit_card:
      - billing_address

As stated above the dependency declaration is not bidirectional. If the presence of a billing_address shall also require the credit_card property to be required the dependency has to be declared separately:


.. code-block:: json

    {
        "type": "object",
        "properties": {
            "credit_card": {
                "type": "integer"
            },
            "billing_address": {
                "type": "string"
            }
        },
        "dependencies": {
            "credit_card": [
                "billing_address"
            ],
            "billing_address": [
                "credit_card"
            ]
        }
    }

Schema Dependencies
^^^^^^^^^^^^^^^^^^^

Schema dependencies are currently not supported.

Pattern Properties
------------------

Pattern properties are currently not supported.