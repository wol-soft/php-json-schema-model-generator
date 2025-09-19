PopulatePostProcessor
=====================

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
