Filter
======

Filter can be used to preprocess values. Filters are applied after the required and type validation. If a filter is applied to a property which has a type which is not supported by the filter an exception will be thrown.
Filters can be either supplied as a string or as a list of filters (multiple filters can be applied to a single property):

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "firstname": {
                "type": "string",
                "filter": "trim"
            },
            "lastname": {
                "type": "string",
                "filter": [
                    "trim"
                ]
            }
        }
    }

If a list is used filters may include additional option parameters. In this case a single filter must be provided as an object with the key **filter** defining the filter:

.. code-block:: json

    {
        "type": "object",
        "properties": {
            "created": {
                "type": "string",
                "filter": [
                    {
                        "filter": "dateTime",
                        "denyEmptyValue": true
                    }
                ]
            }
        }
    }

.. warning::

    Filters may change the type of the property. For example the builtin filter **dateTime** creates a DateTime object. Consequently further validations like pattern checks for the string property won't be performed.

    The return type of the last applied filter will be used to define the type of the property inside the generated model (in the example given above the method **getCreated** will return a DateTime object).

Builtin filter
--------------

trim
^^^^

The trim filter is only valid for string properties.

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "filter": "trim",
                "minLength": 2
            }
        }
    }

Let's have a look how the generated model behaves:

.. code-block:: php

    // valid, the name will be NULL as the name is not required
    $person = new Person([]);

    // Throws an exception as the name provides an invalid value after being trimmed.
    // Exception: 'Value for name must not be shorter than 2'
    $person = new Person(['name' => '   A   ']);

    // A valid example
    $person = new Person(['name' => '   Albert ']);
    $person->getName(); // returns 'Albert'
    // the raw model data input is not affected by the filter
    $person->getRawModelDataInput(); // returns ['name' => '   Albert ']

    // If setters are generated the setters also execute the filter and perform validations.
    // Exception: 'Value for name must not be shorter than 2'
    $person->setName('  D ');

If the filter trim is used for a property which doesn't require a string value and a non string value is provided an exception will be thrown:

* Filter trim is not compatible with property type __TYPE__ for property __PROPERTY_NAME__

dateTime
^^^^^^^^

The dateTime filter is only valid for string properties.

.. code-block:: json

    {
        "$id": "car",
        "type": "object",
        "properties": {
            "productionDate": {
                "type": "string",
                "filter": "dateTime"
            }
        }
    }

.. warning::

    The dateTime filter modifies the type of your property

Generated interface:

.. code-block:: php

    public function setProductionDate(?string $productionDate): self;
    public function getProductionDate(): ?DateTime;

Let's have a look how the generated model behaves:

.. code-block:: php

    // valid, the name will be NULL as the productionDate is not required
    $person = new Car([]);

    // Throws an exception as the provided value is not valid for the DateTime constructor
    $person = new Car(['productionDate' => 'Hello']);

    // A valid example
    $person = new Car(['productionDate' => '2020-10-10']);
    $person->productionDate(); // returns a DateTime object
    // the raw model data input is not affected by the filter
    $person->getRawModelDataInput(); // returns ['productionDate' => '2020-10-10']

Additional options
~~~~~~~~~~~~~~~~~~

================ ============= ===========
Option           Default value Description
================ ============= ===========
convertNullToNow false         If null is provided a DateTime object with the current time will be created (works only if the property isn't required as null would be denied otherwise before the filter is executed)
denyEmptyValue   false         An empty string value will be denied (by default an empty string value will result in a DateTime object with the current time)
================ ============= ===========

Custom filter
-------------

You can implement custom filter and use them in your schema files. You must add your custom filter to the generator configuration to make them available.

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->addFilter(new UppercaseFilter())
    );

Your filter must implement the interface **PHPModelGenerator\\PropertyProcessor\\Filter\\FilterInterface**. Make sure the given callable array returned by **getFilter** is accessible as well during the generation process as during code execution using the generated model.
The callable filter method must be a static method. Internally it will be called via *call_user_func_array*. A custom filter may look like:

.. code-block:: php

    namespace MyApp\Model\Generator\Filter;

    use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;

    class UppercaseFilter implements FilterInterface
    {
        public static function uppercase(?string $value): ?string
        {
            // we want to handle strings and null values with this filter
            return $value !== null ? strtoupper($value) : null;
        }

        public function getAcceptedTypes(): array
        {
            return ['string'];
        }

        public function getToken(): string
        {
            return 'uppercase';
        }

        public function getFilter(): array
        {
            return [self::class, 'uppercase'];
        }
    }

If the custom filter is added to the generator configuration you can now use the filter in your schema and the generator will resolve the function:


.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "filter": [
                    "uppercase",
                    "trim"
                ]
            }
        }
    }

.. code-block:: php

    $person = new Person(['name' => '   Albert ']);
    $person->getName(); // returns 'ALBERT'

Accessing additional filter options
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Filters may handle additional configuration options like the builtin dateTime-filter. The options will be passed as an array as the second argument of your filter function. Let's assume you want to add additional options to your uppercase-filter you'd add the options parameter to your static filter implementation:

.. code-block:: php

    public static function uppercase(?string $value, array $options): ?string
    {
        // do something with a custom option
        if ($options['onlyVocals'] ?? false) {
            // uppercase only the vocals of the provided value
        }

        // ... default implementation
    }

The option will be available if your JSON-Schema uses the object-notation for the filter:

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "filter": [
                    {
                        "filter": "uppercase",
                        "onlyVocals": true
                    },
                    "trim"
                ]
            }
        }
    }
