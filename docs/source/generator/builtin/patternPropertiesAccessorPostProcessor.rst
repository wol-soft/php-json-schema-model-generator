PatternPropertiesAccessorPostProcessor
======================================

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor());

The **PatternPropertiesAccessorPostProcessor** adds methods to your model to work with `pattern properties <../../complexTypes/object.html#pattern-properties>`__ on your objects. The methods will only be added if the schema for the object defines pattern properties.

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

Generated interface with the **PatternPropertiesAccessorPostProcessor**:

.. code-block:: php

    public function getRawModelDataInput(): array;

    public function setExample(float $example): static;
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

    If you want to modify your object by adding or removing pattern properties after the object instantiation you can use the `AdditionalPropertiesAccessorPostProcessor <additionalPropertiesAccessorPostProcessor.html>`__ or the `PopulatePostProcessor <populatePostProcessor.html>`__
