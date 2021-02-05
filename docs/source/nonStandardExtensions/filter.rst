Filter
======

Filter can be used to preprocess values. Filters are applied after the required and type validation. If a filter is applied to a property which has a type which is not supported by the filter an IncompatibleFilterException will be thrown.
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

If the implementation of a filter throws an exception this exception will be caught by the generated model. The model will either throw the exception directly as a InvalidFilterValueException or insert it into the error collection based on your GeneratorConfiguration (compare `collecting errors <../gettingStarted.html#collect-errors-vs-early-return>`__). This behaviour also allows you to hook into the validation process and execute extended validations on the provided property.

If multiple filters are applied to a single property they will be executed in the order of their definition inside the JSON Schema.

Filters may include additional option parameters. In this case a single filter must be provided as an object with the key **filter** defining the filter (possible as a single filter or as a list):

.. code-block:: json

    {
        "type": "object",
        "properties": {
            "created": {
                "type": "string",
                "filter": {
                    "filter": "dateTime",
                    "denyEmptyValue": true
                }
            },
            "updated": {
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

Array filter
------------

Filters may be applied to arrays. In this case the filter operates on the whole array.

.. code-block:: json

    {
        "type": "object",
        "properties": {
            "names": {
                "type": "array",
                "filter": "notEmpty",
                "items": {
                    "type": "string",
                    "filter": "trim"
                }
            }
        }
    }

The array filter is executed before the items are processed. Consequently strings which aren't empty at the beginning but are empty after the trim filter is applied to each element won't be filtered out in the example given above.

It is not possible to use transforming filters on arrays.

Transforming filter
-------------------

.. warning::

    Read this section carefully and understand it if you want to use filters which transform the type of the property without breaking your bones

    You may keep it simple and skip this for your first tries and only experiment with non-transforming filters like the trim filter

Filters may change the type of the property. For example the builtin filter **dateTime** creates a DateTime object. Consequently further type-related validations like pattern checks for the string property won't be performed. If you use a transforming filter which transforms the value into another accepted type (eg. your property accepts ['string', 'integer'] and your transforming filter transforms provided strings into integers) the additional provided validators for integers (like minimum or maximum checks) will be executed (only if your property accepts integer values; if the property only accepts strings and the transforming filter converts them to integer values integer validators won't be added to the property). Additionally enum validations will not be executed if an already transformed value is provided.

As the required check is executed before the filter a filter may transform a required value into a null value. Be aware when writing custom filters which transform values to not break your validation rules by adding filters to a property.

Only one transforming filter per property is allowed. may be positioned anywhere in the filter chain of a single property. If multiple filters are applied and a transforming filter is among them you have to make sure the property types are compatible. If you use a custom filter after the dateTime filter for example the custom filter has to accept a DateTime value. Filters used before a transforming filter must accept the base type of the property the filter is applied to defined in the schema. If the transformation of a property fails (the transforming filter throws an exception), subsequent filters won't be executed as their execution would add another error due to incompatible types which is irrelevant for the currently provided value.

If you write a custom transforming filter you must define the return type of your filter function as the implementation uses Reflection methods to determine to which type a value is transformed by a filter.

The return type of the transforming filter will be used to define the type of the property inside the generated model (in the example one section above given above the method **getCreated** will return a DateTime object). Additionally the generated model also accepts the transformed type as input type. So **setCreated** will accept a string and a DateTime object. If an already transformed value is provided the filter which transforms the value will **not** be executed. Also all filters which are defined before the transformation will **not** be executed (eg. a trim filter before a dateTime filter will not be executed if a DateTime object is provided).

If you use a filter on a property which accepts multiple types (eg. explicit null ['string', 'null'] or ['string', 'integer']) the filter must accept each of the types defined on the property.

Exceptions from filter
----------------------

If a filter is called with a type which isn't supported by the filter a *PHPModelGenerator\\Exception\\ValidationException\\IncompatibleFilterException* will be thrown which provides the following methods to get further error details:

.. code-block:: php

    // returns the token of the filter which wasn't able to be processed
    public function getFilterToken(): string
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

If the filter throws an exception during the execution the exception will be caught and converted into a *PHPModelGenerator\\Exception\\Filter\\InvalidFilterValueException* which provides the following methods to get further error details:

.. code-block:: php

    // returns the token of the filter which wasn't able to be processed
    public function getFilterToken(): string
    // Returns the exception which was thrown inside the filter
    public function getFilterException(): Throwable
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Builtin filter
--------------

trim
^^^^

The trim filter is only valid for string and null properties.

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
    // MinLengthException: 'Value for name must not be shorter than 2'
    $person = new Person(['name' => '   A   ']);

    // A valid example
    $person = new Person(['name' => '   Albert ']);
    $person->getName(); // returns 'Albert'
    // the raw model data input is not affected by the filter
    $person->getRawModelDataInput(); // returns ['name' => '   Albert ']

    // If setters are generated the setters also execute the filter and perform validations.
    // MinLengthException: 'Value for name must not be shorter than 2'
    $person->setName('  D ');

If the filter trim is used for a property which doesn't require a string value and a non string value is provided an exception will be thrown:

* Filter trim is not compatible with property type __TYPE__ for property __PROPERTY_NAME__

notEmpty
^^^^^^^^

The dateTime filter is only valid for array and null properties.

.. code-block:: json

    {
        "$id": "family",
        "type": "object",
        "properties": {
            "members": {
                "type": "array",
                "filter": "notEmpty"
            }
        }
    }

Let's have a look how the generated model behaves:

.. code-block:: php

    // A valid example
    $family = new Family(['members' => [null, null]]]);
    $family->getMembers(); // returns an empty array
    // the raw model data input is not affected by the filter
    $family->getRawModelDataInput(); // returns ['members' => [null, null]]

    $family->setMembers(['Hannes', null]);
    $family->getMembers(); // returns ['Hannes']

dateTime
^^^^^^^^

The dateTime filter is only valid for string and null properties.

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

    // $productionDate accepts string|DateTime|null
    // if a string is provided the string will be transformed into a DateTime
    public function setProductionDate($productionDate): self;
    public function getProductionDate(): ?DateTime;

Let's have a look how the generated model behaves:

.. code-block:: php

    // valid, the productionDate will be NULL as the productionDate is not required
    $car = new Car([]);

    // Throws an InvalidFilterValueException as the provided value is not valid for the DateTime constructor
    $car = new Car(['productionDate' => 'Hello']);

    // A valid example
    $car = new Car(['productionDate' => '2020-10-10']);
    $car->productionDate(); // returns a DateTime object
    // the raw model data input is not affected by the filter
    $car->getRawModelDataInput(); // returns ['productionDate' => '2020-10-10']

    // Another valid example with an already transformed value
    $car = new Car(['productionDate' => $myDateTimeObject]);

Additional options
~~~~~~~~~~~~~~~~~~

======================= ============= ===========
Option                  Default value Description
======================= ============= ===========
convertNullToNow        false         If null is provided a DateTime object with the current time will be created (works only if the property isn't required as null would be denied otherwise before the filter is executed)
convertEmptyValueToNull false         If an empty string is provided and this option is set to true the property will contain null after the filter has been applied
denyEmptyValue          false         An empty string value will be denied (by default an empty string value will result in a DateTime object with the current time)
createFromFormat        null          Provide a pattern which is used to parse the provided value (DateTime object will be created via DateTime::createFromFormat if a format is provided)
outputFormat            DATE_ISO8601  The output format if serialization is enabled and toArray or toJSON is called on a transformed property. If a createFromFormat is defined but no outputFormat the createFromFormat value will override the default value
======================= ============= ===========

.. hint::

    If the dateTime filter is used without the createFromFormat option the string will be passed into the DateTime constructor. Consequently also strings like '+1 day' will be converted to the corresponding DateTime objects.

.. hint::

    Beside defining custom formats the formatting options *createFromFormat* and *outputFormat* also accept PHPs builtin constants. To accept values formatted with DATE_ATOM simply set the option *createFromFormat* to **ATOM**. The following constants are available: ATOM, COOKIE, ISO8601, RFC822, RFC850, RFC1036, RFC1123, RFC2822, RFC3339, RFC3339_EXTENDED, RFC7231, RSS, W3C

Custom filter
-------------

You can implement custom filter and use them in your schema files. You must add your custom filter to the generator configuration to make them available.

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->addFilter(new UppercaseFilter())
    );

Your filter must implement the interface **PHPModelGenerator\\Filter\\FilterInterface**. Make sure the given callable array returned by **getFilter** is accessible as well during the generation process as during code execution using the generated model.
The callable filter method must be a static method. Internally it will be called via *call_user_func_array*. A custom filter may look like:

.. code-block:: php

    namespace MyApp\Model\Generator\Filter;

    use PHPModelGenerator\Filter\FilterInterface;

    class UppercaseFilter implements FilterInterface
    {
        public static function uppercase(?string $value): ?string
        {
            // we want to handle strings and null values with this filter
            return $value !== null ? strtoupper($value) : null;
        }

        public function getAcceptedTypes(): array
        {
            // return an array of types which can be handled by the filter.
            // valid types are: [integer, number, boolean, string, array, null]
            // or available classes (FQCN required, eg. DateTime::class)
            return ['string', 'null'];
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

.. hint::

    If your filter accepts null values add 'null' to your *getAcceptedTypes* to make sure your filter is compatible with explicit null type.

.. hint::

    If a filter with the token of your custom filter already exists the existing filter will be overwritten when adding the filter to the generator configuration. By overwriting filters you may change the behaviour of builtin filters by replacing them with your custom implementation.

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

Custom transforming filter
^^^^^^^^^^^^^^^^^^^^^^^^^^

If you want to provide a custom filter which transforms a value (eg. redirect data into a manually written model, transforming between data types [eg. accepting values as an integer but handle them internally as binary strings]) you must implement the **PHPModelGenerator\\Filter\\TransformingFilterInterface**. This interface adds the **getSerializer** method to your filter. The method is similar to the **getFilter** method. It must return a callable which is available during the render process as well as during code execution. The returned callable must return null or a string and undo a transformation (eg. the serializer method of the builtin **dateTime** filter transforms a DateTime object back into a formatted string). The serializer method will be called with the current value of the property as the first argument and with the (optionally provided) additional options of the filter as the second argument. Your custom transforming filter might look like:

The custom serializer method will be called if the model utilizing the custom filter is generated with `serialization methods <../gettingStarted.html#serialization-methods>`__ and *toArray* or *toJSON* is called.

.. code-block:: php

    namespace MyApp\Model\Generator\Filter;

    use MyApp\Model\ManuallyWrittenModels\Customer;
    use PHPModelGenerator\Filter\TransformingFilterInterface;

    class CustomerFilter implements TransformingFilterInterface
    {
        // Let's assume you have written a Customer model manually eg. due to advanced validations
        // and you want to use the Customer model as a part of your generated model
        public static function instantiateCustomer(?array $data, array $additionalOptions): ?Customer
        {
            return $data !== null ? new Customer($data, $additionalOptions) : null;
        }

        // $customer will contain the current value of the property the filter is applied to
        // $additionalOptions will contain all additional options from the JSON Schema
        public static function serializeCustomer(?Customer $customer, array $additionalOptions): ?string
        {
            return $data !== null ? $customer->serialize($additionalOptions) : null;
        }

        public function getAcceptedTypes(): array
        {
            return ['string', 'null'];
        }

        public function getToken(): string
        {
            return 'customer';
        }

        public function getFilter(): array
        {
            return [self::class, 'instantiateCustomer'];
        }

        public function getSerializer(): array
        {
            return [self::class, 'serializeCustomer'];
        }
    }
