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
    public function setName(string $name): self;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getName(): ?string;
    public function setCar(Car $name): self;
    public function getCar(): ?Car;

    // class Car
    public function setModel(string $name): self;
    public function getModel(): ?string;
    public function setPs(int $name): self;
    public function getPs(): ?int;

Possible exceptions:

.. code-block:: none

    * Invalid type for car. Requires object, got __TYPE__

    * Invalid nested object for property car:
      - Invalid type for model. Requires string, got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

The nested object will be validated in the nested class Car which may throw additional exceptions if invalid data is provided. If the internal validation of a nested object fails a *PHPModelGenerator\\Exception\\Generic\\NestedObjectException* will be thrown which provides the following methods to get further error details:

.. code-block:: php

    // Returns the exception which was thrown in the nested object
    public function getNestedException()
    // get the name of the property which contains the nested object
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

If `error collection <../gettingStarted.html#collect-errors-vs-early-return>`__ is enabled the nested exception returned by `getNestedException` will be an **ErrorRegistryException** containing all validation errors of the nested object. Otherwise it will contain the first validation error which occurred during the validation of the nested object.

.. hint::

    If the class created for a nested object is instantiated manually you will either get a collection exception or a specific exception based on your error collection configuration if invalid data is provided.

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

For the class name of a nested class the `$id` property of the nested object is used. If the id property isn't present the property key will be prefixed with the parent class. If an object `Person` has a nested object `car` without a `$id` the class for car will be named **Person_Car**.

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

    public function setUnderscorePropertyMinus(string $name): self;
    public function getUnderscorePropertyMinus(): ?string;
    public function setCapsAndSpace100(string $name): self;
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

.. hint::

    Properties defined in the `required` array but not defined in the `properties` will be added to the interface of the generated class.

    A schema defining only the required property `example` consequently will provide the methods `getExample(): mixed` and `setExample(mixed $value): self`.

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

The thrown exception will be a *PHPModelGenerator\\Exception\\Object\\MaxPropertiesException* or a *PHPModelGenerator\\Exception\\Object\\MinPropertiesException* which provides the following methods to get further error details:

.. code-block:: php

    // for a MaxPropertiesException: get the minimum amount of object properties
    public function getMaxProperties(): int
    // for a MinPropertiesException: get the maximum amount of object properties
    public function getMinProperties(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Additional Properties
---------------------

Using the keyword `additionalProperties` the object can be limited to not contain any additional properties by providing `false`. If a schema is provided all additional properties must be valid against the provided schema. Simple checks like 'must provide a string' are possible as well as checks like 'must contain an object with a specific structure'.

.. hint::

    If you define constraints via `additionalProperties` you may want to use the `AdditionalPropertiesAccessorPostProcessor <../generator/postProcessor.html#additionalpropertiesaccessorpostprocessor>`__ to access and modify your additional properties.

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

* Provided JSON for example contains not allowed additional properties [additional1, additional2]

The thrown exception will be a *PHPModelGenerator\\Exception\\Object\\AdditionalPropertiesException* which provides the following methods to get further error details:

.. code-block:: php

    // Get a list of all additional properties which are denied by the schema
    public function getAdditionalProperties(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

If invalid additional properties are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Provided JSON for example contains invalid additional properties.
      - invalid additional property 'additional1'
        * Invalid type for name. Requires string, got integer
      - invalid additional property 'additional2'
        * Invalid type for age. Requires int, got string

The thrown exception will be a *PHPModelGenerator\\Exception\\Object\\InvalidAdditionalPropertiesException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by property names
    public function getNestedExceptions(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

.. warning::

    The validation of additional properties is independently from the `implicit null <../gettingStarted.html#implicit-null>`__ setting. If you require your additional properties to accept null define a `multi type <multiType.html>`__ with explicit null.

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

    // class Family, arrays type hinted in docblocks with Family_Person[]
    public function setMembers(array $members): self;
    public function getMembers(): ?array;

    // class Person, arrays type hinted in docblocks with Family_Person[]
    public function setName(string $name): self;
    public function getName(): ?string;
    public function setChildren(array $name): self;
    public function getChildren(): ?array;

Property Names
--------------

With the keyword `propertyNames` rules can be defined which must be fulfilled by each given property.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "propertyNames": {
            "pattern": "^test[0-9]+$",
            "maxLength": 8
        }
    }

Compare `strings <../types/string.html>`__ for information concerning possible property name validators.

Exceptions contain detailed information about the violations:

.. code-block:: none

    Provided JSON for example contains properties with invalid names.
      - invalid property 'test12345a'
        * Value for property name doesn't match pattern ^test[0-9]+$
        * Value for property name must not be longer than 8
      - invalid property 'test123456789'
        * Value for property name must not be longer than 8

The thrown exception will be a *PHPModelGenerator\\Exception\\Object\\InvalidPropertyNamesException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by property names
    // each entry contains all name violations of the given property
    public function getNestedExceptions(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

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

The thrown exception will be a *PHPModelGenerator\\Exception\\Dependency\\InvalidPropertyDependencyException* which provides the following methods to get further error details:

.. code-block:: php

    // returns an array containing all missing attributes
    public function getMissingAttributes(): array
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

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

Schema dependencies allow you to define a schema which must be fulfilled if a given property is present. The schema provided for the property must be either an object schema, a composition schema or a reference to an object schema.

.. code-block:: json

    {
        "type": "object",
        "$id": "CreditCardOwner"
        "properties": {
            "credit_card": {
                "type": "integer"
            }
        },
        "dependencies": {
            "credit_card": {
                "properties": {
                    "billing_address": {
                        "type": "string"
                    },
                    "date_of_birth": {
                        "type": "string"
                    }
                },
                "required": [
                    "date_of_birth"
                ]
            }
        }
    }

The properties of the dependant schema will be transferred to the base model during the model generation process. If the property which defines the dependency isn't present they will not be required by the base model.

Generated interface:

.. code-block:: php

    // class CreditCardOwner
    // base properties
    public function setCreditCard(int $creditCard): self;
    public function getCreditCard(): ?int;

    // inherited properties
    // the inherited properties will not be type hinted as they may contain any value if credit_card isn't present.
    public function setBillingAddress($billingAddress): self;
    public function getBillingAddress();
    public function setDateOfBirth($dateOfBirth): self;
    public function getDateOfBirth();

.. hint::

    Basically this means your base object gets getters and setters for the additional properties transferred from the schema dependency but this getters and setters won't perform any validation. If you require type checks and validations performed on the properties define them in your main schema as not required properties and require them as a property dependency.

Possible exceptions:

.. code-block:: none

    Invalid schema which is dependant on credit_card:
      - Missing required value for date_of_birth

The thrown exception will be a *PHPModelGenerator\\Exception\\Dependency\\InvalidSchemaDependencyException* which provides the following methods to get further error details:

.. code-block:: php

    // Returns the exception which covers all validation errors of the dependant schema
    public function getDependencyException(): Throwable
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Multiple violations against the schema dependency may be included.

Pattern Properties
------------------

Using the keyword `patternProperties` further restrictions for properties matching a pattern can be defined.

.. hint::

    If you define constraints via `patternProperties` you may want to use the `PatternPropertiesAccessorPostProcessor <../generator/postProcessor.html#patternpropertiesaccessorpostprocessor>`__ to access your pattern properties.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "integer"
            }
        },
        "patternProperties": {
            "^a": {
                "type": "string"
            }
        }
    }

Possible exceptions:

If invalid pattern properties are provided a detailed exception will be thrown containing all violations:

.. code-block:: none

    Provided JSON for Example contains invalid pattern properties.
      - invalid property 'a0' matching pattern '\^a'
        * Invalid type for pattern property. Requires string, got integer

The thrown exception will be a *PHPModelGenerator\\Exception\\Object\\InvalidPatternPropertiesException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a two-dimensional array which contains all validation exceptions grouped by property names
    public function getNestedExceptions(): array
    // get the pattern which lead to the error
    public function getPattern(): string
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()
