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

The **PopulatePostProcessor** adds a populate method to your generated model. The populate method accepts an array which might contain any subset of the model's properties. All properties present in the provided array will be validated according to the validation rules from the JSON-Schema. If all values are valid the properties will be updated otherwise an exception will be thrown (if error collection is enabled an exception containing all violations, otherwise on the first occurring error, compare `collecting errors <../gettingStarted.html#collect-errors-vs-early-return>`__). Also basic model constraints like `minProperties`, `maxProperties` or `propertyNames` will be validated as the provided array may add additional properties to the model. If the model is updated also the values which can be fetched via `getRawModelDataInput` will be updated.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "value": {
                "type": "string"
            }
        }
    }

Generated interface with the **PopulatePostProcessor**:

.. code-block:: php

    public function getRawModelDataInput(): array;
    public function populate(array $modelData): self;

    public function setExample(float $example): self;
    public function getExample(): float;

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

Custom Post Processors
----------------------

You can implement custom post processors to accomplish your tasks. Each post processor must implement the **PHPModelGenerator\\SchemaProcessor\\PostProcessor\\PostProcessorInterface**. If you have implemented a post processor add the post processor to your `ModelGenerator` and the post processor will be executed for each class.

A custom post processor which adds a custom trait to the generated model (eg. a trait adding methods for an active record pattern implementation) may look like:

.. code-block:: php

    namespace MyApp\Model\Generator\PostProcessor;

    use MyApp\Model\ActiveRecordTrait;
    use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessorInterface;

    class ActiveRecordPostProcessor implements PostProcessorInterface
    {
        public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
        {
            $schema->addTrait(ActiveRecordTrait::class);
        }
    }
