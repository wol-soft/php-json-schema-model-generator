AdditionalPropertiesAccessorPostProcessor
=========================================

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));

The **AdditionalPropertiesAccessorPostProcessor** adds methods to your model to work with `additional properties <../../complexTypes/object.html#additional-properties>`__ on your objects. By default the post processor only adds methods to objects from a schema which defines constraints for additional properties. If the first constructor parameter *$addForModelsWithoutAdditionalPropertiesDefinition* is set to true the methods will also be added to objects generated from a schema which doesn't define additional properties constraints. If the *additionalProperties* keyword in a schema is set to false the methods will never be added.

.. note::

    If the `deny additional properties setting <../../gettingStarted.html#deny-additional-properties>`__ is set to true the setting *$addForModelsWithoutAdditionalPropertiesDefinition* is ignored as all objects which don't define additional properties are restricted to the defined properties

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

    public function setExample(float $example): static;
    public function getExample(): float;

    public function meta(): Meta;
    public function additionalProperties(): AdditionalPropertiesAccessor;

The ``additionalProperties()`` method returns an accessor object with the following interface:

.. code-block:: php

    public function getAll(): array;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): bool;

.. note::

    The methods **set** and **remove** on the accessor are only available if the `immutable setting <../../gettingStarted.html#immutable-classes>`__ is set to false.

When the ``additionalProperties`` keyword provides a schema that constrains the value type, a typed companion class ``{ModelName}AdditionalProperties`` is generated that narrows the return and parameter types of the accessor methods accordingly.

**getAll**: Returns all additional properties currently part of the model as key-value pairs. Properties defined in the schema (in this case *example*) are not included. Unlike ``meta()->rawInput()``, the values returned here are the processed values — if the schema defines an object schema for additional properties, an array of object instances is returned; if a `filter <../../nonStandardExtensions/filter.html>`__ is applied, the filtered (and for transforming filters, transformed) values are returned.

**get**: Returns the current value of a single additional property. Returns null if the requested property does not exist. Like ``getAll``, returns the processed value.

**set**: Adds or updates an additional property. Performs all necessary validations including property name constraints and min/max properties limits. If the additional properties are processed via a transforming filter an already transformed value will be accepted. Throws *RegularPropertyAsAdditionalPropertyException* if the key conflicts with a regularly-defined schema property.

**remove**: Removes an existing additional property from the model. Returns true if the property was removed, false if it did not exist. May throw a *MinPropertiesException* if removal would produce an invalid model state.

Serialization
~~~~~~~~~~~~~

By default additional properties are only included in the serialized models if the *additionalProperties* field is set to true or contains further restrictions. If the option *$addForModelsWithoutAdditionalPropertiesDefinition* is set to true also additional properties for entities which don't define the *additionalProperties* field will be included in the serialization result. If the **AdditionalPropertiesAccessorPostProcessor** is applied and `serialization <../../gettingStarted.html#serialization-methods>`__ is enabled the additional properties will be merged into the serialization result. If the additional properties are processed via a transforming filter each value will be serialized via the serialisation method of the transforming filter.
