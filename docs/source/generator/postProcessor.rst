Post Processor
==============

Post processors provide an easy way to extend your generated code. A post processor can be added to your `ModelGenerator` object:

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new PopulatePostProcessor());

    $files = $generator->generateModelDirectory(__DIR__ . '/result')
        ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');

All added post processors will be executed after a schema was processed and before a model is rendered. Consequently a post processor can be used to change the generated class or to extend the class. Also additional tasks which don't change the rendered code may be executed (eg. create a documentation file for the class, create SQL create statements for tables representing the class, ...).

Builtin Post Processors
-----------------------

PopulatePostProcessor
^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new PopulatePostProcessor());

The **PopulatePostProcessor** adds a populate method to your generated model. The populate method accepts an array which might contain any subset of the model's properties. All properties present in the provided array will be validated according to the validation rules from the JSON-Schema. If all values are valid the properties will be updated otherwise an exception will be thrown (if error collection is enabled an exception containing all violations, otherwise on the first occurring error, compare `collecting errors <../gettingStarted.html#collect-errors-vs-early-return>`__). Also basic model constraints like `minProperties`, `maxProperties` or `propertyNames` will be validated as the provided array may add additional properties to the model. If the model is updated also the values which can be fetched via `getRawModelDataInput` will be updated.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        }
    }

Generated interface with the **PopulatePostProcessor**:

.. code-block:: php

    public function getRawModelDataInput(): array;

    public function setExample(float $example): self;
    public function getExample(): float;

    public function populate(array $modelData): self;

Now let's have a look at the behaviour of the generated model:

.. code-block:: php

    // initialize the model with a valid value
    $example = new Example(['value' => 'Hello World']);
    $example->getRawModelDataInput(); // returns ['value' => 'Hello World']

    // add an additional property to the model.
    // if additional property constraints are defined in your JSON-Schema
    // each additional property will be validated against the defined constraints.
    $example->populate(['additionalValue' => 12]);
    $example->getRawModelDataInput(); // returns ['value' => 'Hello World', 'additionalValue' => 12]

    // update an existing property with a valid value
    $example->populate(['value' => 'Good night!']);
    $example->getRawModelDataInput(); // returns ['value' => 'Good night!', 'additionalValue' => 12]

    // update an existing property with an invalid value which will throw an exception
    try {
        $example->populate(['value' => false]);
    } catch (Exception $e) {
        // perform error handling
    }
    // if the update of the model fails no values will be updated
    $example->getRawModelDataInput(); // returns ['value' => 'Good night!', 'additionalValue' => 12]

.. warning::

    If the **PopulatePostProcessor** is added to your model generator the populate method will be added to the model independently of the `immutable setting <../gettingStarted.html#immutable-classes>`__.

The **PopulatePostProcessor** will also resolve all hooks which are applied to setters. Added code will be executed for all properties changed by a populate call. Schema hooks which implement the **SetterAfterValidationHookInterface** will only be executed if all provided properties pass the validation.

AdditionalPropertiesAccessorPostProcessor
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

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

PatternPropertiesAccessorPostProcessor
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor());

The **PatternPropertiesAccessorPostProcessor** adds methods to your model to work with `pattern properties <../complexTypes/object.html#pattern-properties>`__ on your objects. The methods will only be added if the schema for the object defines pattern properties.

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
        "patternProperties": {
            "^a": {
                "type": "string"
            },
            "^b": {
                "key": "numbers"
                "type": "integer"
            },
        }
    }

Generated interface with the **AdditionalPropertiesAccessorPostProcessor**:

.. code-block:: php

    public function getRawModelDataInput(): array;

    public function setExample(float $example): self;
    public function getExample(): float;

    public function getPatternProperties(string $key): array;

The added method **getPatternProperties** can be used to fetch a list of all properties matching the given pattern. As *$key* you have to provide the pattern you want to fetch. Alternatively you can define a key in your schema and use the key to fetch the properties.

.. code-block:: php

    $myObject = new Example('a1' => 'Hello', 'b1' => 100);

    // fetches all properties matching the pattern '^a', consequently will return ['a1' => 'Hello']
    $myObject->getPatternProperties('^a');

    // fetches all properties matching the pattern '^b' (which has a defined key), consequently will return ['b1' => 100]
    $myObject->getPatternProperties('numbers');

.. note::

    If you want to add or remove pattern properties to your object after the object instantiation you can use the `AdditionalPropertiesAccessorPostProcessor <generator/postProcessor.html#additionalpropertiesaccessorpostprocessor>`__ or the `PopulatePostProcessor <generator/postProcessor.html#populatepostprocessor>`__

Custom Post Processors
----------------------

You can implement custom post processors to accomplish your tasks. Each post processor must extend the class **PHPModelGenerator\\SchemaProcessor\\PostProcessor\\PostProcessor**. If you have implemented a post processor add the post processor to your `ModelGenerator` and the post processor will be executed for each class.

A custom post processor which adds a custom trait to the generated model (eg. a trait adding methods for an active record pattern implementation) may look like:

.. code-block:: php

    namespace MyApp\Model\Generator\PostProcessor;

    use MyApp\Model\ActiveRecordTrait;
    use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

    class ActiveRecordPostProcessor extends PostProcessor
    {
        public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
        {
            $schema->addTrait(ActiveRecordTrait::class);
        }
    }

.. hint::

    For examples how to implement a custom post processor have a look at the built in post processors located at **src/SchemaProcessor/PostProcessor/**

What can you do inside your custom post processor?

* Add additional traits and interfaces to your models
* Add additional methods and properties to your models
* Hook via **SchemaHooks** into the generated source code and add your snippets at defined places inside the model:

    * Implement the **ConstructorBeforeValidationHookInterface** to add code to the beginning of your constructor
    * Implement the **ConstructorAfterValidationHookInterface** to add code to the end of your constructor
    * Implement the **GetterHookInterface** to add code to your getter methods
    * Implement the **SetterBeforeValidationHookInterface** to add code to the beginning of your setter methods
    * Implement the **SetterAfterValidationHookInterface** to add code to the end of your setter methods
    * Implement the **SerializationHookInterface** to add code to the end of your serialization process

.. warning::

    If a setter for a property is called with the same value which is already stored internally (consequently no update of the property is required), the setters will return directly and as a result of that the setter hooks will not be executed.

    This behaviour also applies also to properties changed via the *populate* method added by the `PopulatePostProcessor <#populatepostprocessor>`__ and the *setAdditionalProperty* method added by the `AdditionalPropertiesAccessorPostProcessor <#additionalpropertiesaccessorpostprocessor>`__

To execute code before/after the processing of the schemas override the methods **preProcess** and **postProcess** inside your custom post processor.