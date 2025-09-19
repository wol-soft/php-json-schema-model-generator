AdditionalPropertiesAccessorPostProcessor
=========================================

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));

The **AdditionalPropertiesAccessorPostProcessor** adds methods to your model to work with `additional properties <../complexTypes/object.html#additional-properties>`__ on your objects. By default the post processor only adds methods to objects from a schema which defines constraints for additional properties. If the first constructor parameter *$addForModelsWithoutAdditionalPropertiesDefinition* is set to true the methods will also be added to objects generated from a schema which doesn't define additional properties constraints. If the *additionalProperties* keyword in a schema is set to false the methods will never be added.

.. note::

    If the `deny additional properties setting <../gettingStarted.html#deny-additional-properties>`__ is set to true the setting *$addForModelsWithoutAdditionalPropertiesDefinition* is ignored as all objects which don't define additional properties are restricted to the defined properties

Added methods
~~~~~~~~~~~~~

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        },
        "additionalProperties": {
            "type": "string"
        }
    }

Generated interface with the **AdditionalPropertiesAccessorPostProcessor**:

.. code-block:: php

    public function getRawModelDataInput(): array;

    public function setExample(float $example): self;
    public function getExample(): float;

    public function getAdditionalProperties(): array;
    public function getAdditionalProperty(string $property): ?string;
    public function setAdditionalProperty(string $property, string $value): self;
    public function removeAdditionalProperty(string $property): bool;

.. note::

    The methods **setAdditionalProperty** and **removeAdditionalProperty** are only added if the `immutable setting <../gettingStarted.html#immutable-classes>`__ is set to false.

**getAdditionalProperties**: This method returns all additional properties which are currently part of the model as key-value pairs where the key is the property name and the value the current value stored in the model. All other properties which are part of the object (in this case the property *example*) will not be included. In opposite to the *getRawModelDataInput* the values provided via this method are the processed values. This means if the schema provides an object-schema for additional properties an array of object instances will be returned. If the additional properties schema contains `filter <../nonStandardExtensions/filter.html>`__ the filtered (and in case of transforming filter transformed) values will be returned.

**getAdditionalProperty**: Returns the current value of a single additional property. If the requested property doesn't exist null will be returned. Returns as well as *getAdditionalProperties* the processed values.

**setAdditionalProperty**: Adds or updates an additional property. Performs all necessary validations like property names or min and max properties validations. If the additional properties are processed via a transforming filter an already transformed value will be accepted. If a property which is regularly defined in the schema a *RegularPropertyAsAdditionalPropertyException* will be thrown. If the change is valid and performed also the output of *getRawModelDataInput* will be updated.

**removeAdditionalProperty**: Removes an existing additional property from the model. Returns true if the additional property has been removed, false otherwise (if no additional property with the requested key exists). May throw a *MinPropertiesException* if the change would result in an invalid model state. If the change is valid and performed also the output of *getRawModelDataInput* will be updated.

Serialization
~~~~~~~~~~~~~

By default additional properties are only included in the serialized models if the *additionalProperties* field is set to true or contains further restrictions. If the option *$addForModelsWithoutAdditionalPropertiesDefinition* is set to true also additional properties for entities which don't define the *additionalProperties* field will be included in the serialization result. If the **AdditionalPropertiesAccessorPostProcessor** is applied and `serialization <../gettingStarted.html#serialization-methods>`__ is enabled the additional properties will be merged into the serialization result. If the additional properties are processed via a transforming filter each value will be serialized via the serialisation method of the transforming filter.
