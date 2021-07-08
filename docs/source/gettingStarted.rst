Getting started
===============

Installation
------------

The recommended way to install php-json-model-generator is through `Composer <http://getcomposer.org>`_:

.. code-block:: console

    composer require --dev wol-soft/php-json-schema-model-generator
    composer require wol-soft/php-json-schema-model-generator-production

To avoid adding all dependencies of the php-json-model-generator to your production dependencies it's recommended to add the library as a dev-dependency and include the php-json-model-generator-production library. The production library provides all classes to run the generated code. Generating the classes should either be a step done in the development environment or as a build step of your application (which is the recommended workflow).

Generating classes
------------------

The base object for generating models is the *Generator*. After you have created a Generator you can use the object to generate your model classes without any further configuration:

.. code-block:: php

    (new Generator())
        ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');

The first parameter of the *generateModels* method must be a class implementing the *SchemaProviderInterface*. The provider fetches the JSON schema files and provides them for the generator. The following providers are available:

=========================== ===========
Provider                    Description
=========================== ===========
RecursiveDirectoryProvider  Fetches all *.json files from the given source directory. Each file must contain a JSON Schema object definition on the top level
OpenAPIv3Provider           Fetches all objects defined in the #/components/schemas section of an Open API v3 spec file
=========================== ===========

The second parameter must point to an existing and empty directory (you may use the *generateModelDirectory* helper method to create your destination directory). This directory will contain the generated PHP classes after the generator is finished.

As an optional parameter you can set up a *GeneratorConfiguration* object to configure your Generator and/or use the method *generateModelDirectory* to generate your model directory (will generate the directory if it doesn't exist; if it exists, all contained files and folders will be removed for a clean generation process):

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->setNamespacePrefix('MyApp\Model')
            ->setImmutable(false)
    );

    $generator
        ->generateModelDirectory(__DIR__ . '/result');
        ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');

The generator will check the given source directory recursive and convert all found \*.json files to models. All JSON-Schema files inside the source directory must provide a schema of an object.

Default interface of configured classes
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The constructor of a generated model takes an array as argument. The array must contain the structure defined in the JSON-Schema which is afterwards validated and applied to the state of the model.

.. code-block:: json

    {
        "$id": "person",
        "type": "object",
        "properties": {
            "name": {
                "type": "string"
            },
            "age": {
                "type": "integer",
                "minimum": 0
            }
        },
        "required": [
            "name"
        ]
    }

After generating a class with this JSON-Schema our class with the name `Person` will provide the following interface (immutability disabled via GeneratorConfiguration):

.. code-block:: php

    // the constructor takes an array with data which is validated and applied to the model
    public function __construct(array $modelData);

    // the method getRawModelDataInput always delivers the raw input which was provided on instantiation
    public function getRawModelDataInput(): array;

    // getters to fetch the validated properties. Age is nullable as it's not required
    public function getName(): string;
    public function getAge(): ?int;

    // setters to change the values of the model after instantiation
    public function setName(string $name): Person;
    public function setAge(int ?$age): Person;

Now let's have a look at the behaviour of the generated model:

.. code-block:: php

    // Throws an exception as the required name isn't provided.
    // Exception: 'Missing required value for name'
    $person = new Person([]);

    // Throws an exception as the name provides an invalid value.
    // Exception: 'Invalid type for name. Requires string, got int'
    $person = new Person(['name' => 12]);

    // Throws an exception as the age contains an invalid value due to the minimum definition.
    // Exception: 'Value for age must not be smaller than 0'
    $person = new Person(['name' => 'Albert', 'age' => -1]);

    // A valid example as the age isn't required
    $person = new Person(['name' => 'Albert']);
    $person->getName(); // returns 'Albert'
    $person->getAge(); // returns NULL
    $person->getRawModelDataInput(); // returns ['name' => 'Albert']

    // If setters are generated the setters also perform validations.
    // Exception: 'Value for age must not be smaller than 0'
    $person->setAge(-10);

Each generated class will implement the interface **PHPModelGenerator\\Interfaces\\JSONModelInterface** implemented in the php-json-schema-model-generator-production repository and thus provide the method *getRawModelDataInput*.

Configuring the generator
-------------------------

The *GeneratorConfiguration* object offers methods to configure the generator in a fluid interface.

Namespace prefix
^^^^^^^^^^^^^^^^

.. code-block:: php

    setNamespacePrefix(string $prefix);

Configures a namespace prefix for all generated classes. By default no namespace prefix will be set. Generated namespaces are PSR-4 compatible.
Further information about the generated namespaces can be found at `Namespaces <generic/namespaces.html>`__.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setNamespacePrefix('MyApp\Model');

Immutable classes
^^^^^^^^^^^^^^^^^

.. code-block:: php

    setImmutable(bool $immutable);

If set to true the generated model classes will be delivered without setter methods for the object properties. By default the classes are generated without setter methods. Each setter will validate the provided value and throw either a specific exception or a collection exception depending on the `error collection configuration <#collect-errors-vs-early-return>`__. If all validations pass the internal value will be updated as well as the value which will be returned when `getRawModelDataInput` is called.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setImmutable(false);

Implicit null
^^^^^^^^^^^^^

By default the properties are strictly checked against their defined types. Consequently if you want a property to accept null you have to extend the type of your property explicitly (eg. ['string', 'null']).

By setting the implicit null option to true all of your object properties which aren't required will implicitly accept null. All properties which are required and don't explicitly allow null in the type definition will still reject null.

If the implicit null option is enabled the interface of your classes may change. If you have disabled immutability the type hints of your optional property setters will be nullable (eg. a string property will be type hinted with `?string`).

.. code-block:: php

    setImplicitNull(bool $allowImplicitNull);

.. code-block:: php

    (new GeneratorConfiguration())
        ->setImplicitNull(true);

Default arrays to empty arrays
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

By default optional properties which contain an `array <complexTypes/array.html>`__ will contain **null** if no array is provided (or null is provided with the `implicit null <#implicit-null>`_ setting enabled). For using the generated getter methods for those properties without a fallback the generator can be configured to default not provided arrays and null values to an empty array (by default this setting is disabled). By enabling this setting it's ensured that all optional arrays will always contain an array even if no default value or null is provided.

.. code-block:: php

    // accessing an array property which may contain null may require a fallback
    foreach ($generatedObject->getItems() ?? [] as $item) {

    // by enabling the default to empty array setting the value returned by getItems will always contain an array
    // consequently no fallback is necessary
    foreach ($generatedObject->getItems() as $item) {

.. hint::

    This setting affects only optional properties.

.. code-block:: php

    setDefaultArraysToEmptyArray(bool $defaultArraysToEmptyArray);

.. code-block:: php

    (new GeneratorConfiguration())
        ->setDefaultArraysToEmptyArray(true);

Deny additional properties
^^^^^^^^^^^^^^^^^^^^^^^^^^

By default each generated object accepts additional properties. For strict property checks which error if undefined properties are provided each object must contain the *additionalProperties* key set to *false*.

By setting the **denyAdditionalProperties** option each object which doesn't specify a value for *additionalProperties* is restricted to the defined properties.

.. code-block:: php

    setDenyAdditionalProperties(bool $denyAdditionalProperties);

.. code-block:: php

    (new GeneratorConfiguration())
        ->setDenyAdditionalProperties(true);

Collect errors vs. early return
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setCollectErrors(bool $collectErrors);

By default the complete input is validated and in case of failing validations all error messages will be thrown in a single exception implementing the ErrorRegistryExceptionInterface interface. If set to false the first failing validation will throw an exception.

The exceptions are implemented in the php-json-schema-model-generator-production repository. Default exceptions:

* Error collection enabled: **PHPModelGenerator\\Exception\\ErrorRegistryException**
* Error collection disabled: specific exceptions extending the **PHPModelGenerator\\Exception\\ValidationException**

All collected exceptions from an ErrorRegistryException are accessible via the *getErrors* method. The collected errors are the specific exceptions extending the **PHPModelGenerator\\Exception\\ValidationException** which would be thrown directly if error collection is disabled. Each exception provides various specific details about the validation violation.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setCollectErrors(false);

.. hint::

    All builtin exceptions provide serialization methods (compare `serialization <#serialization-methods>`_). By default sensitive data (file and line) of the exception will not be serialized. The serialization methods provide another parameter `$stripSensitiveData`. When this parameter is set to false file and line information will be included.

Custom exception classes
^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setErrorRegistryClass(string $exceptionClass);

If you want to customize the exception handling you can set an own ErrorRegistryClass to collect all exceptions via *setErrorRegistryClass*. This setting will only affect the generated code if you have enabled error collection. The exception provided via *setErrorRegistryClass* must implement the ErrorRegistryExceptionInterface.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setErrorRegistryClass(MyCustomException::class);

Serialization methods
^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setSerialization(bool $serialization);

If set to true the serialization methods `toArray`, `toJSON` and `jsonSerialize` will be added to the public interface of the generated classes. By default no serialization methods are added.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setSerialization(true);

Generated interface:

.. code-block:: php

    public function toArray([array $except = [] [, int $depth = 512]]): array;
    public function toJSON([array $except = [] [, int $options = 0 [, int $depth = 512]]]): string;
    public function jsonSerialize(): array;

The generated class will implement the interface **PHPModelGenerator\\Interfaces\\SerializationInterface** implemented in the php-json-schema-model-generator-production repository. This interface can be used to write additional generic modules to handle the generated models. Via the $except parameter you can pass an array of properties which will not be serialized (eg. password data for a user object). The $depth parameter defines the maximum amount of nested objects which are serialized. The $options parameter for the toJSON method provides access to the underlying option bitmask of `json_encode <https://www.php.net/manual/de/function.json-encode.php>`_.

Additionally the class will implement the PHP builtin interface **\JsonSerializable** which allows the direct usage of the generated classes in a custom json_encode.

.. warning::

    If you provide `additional properties <complexTypes/object.html#additional-properties>`__ you may want to use the `AdditionalPropertiesAccessorPostProcessor <generator/postProcessor.html#additionalpropertiesaccessorpostprocessor>`__ as the additional properties by default aren't included into the serialization result.

Output generation process
^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setOutputEnabled(bool $outputEnabled);

Enable or disable output of the generation process to STDOUT. By default the output is enabled.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setOutputEnabled(false);

The output contains information about generated classes, rendered classes, hints and warnings concerning the internal handling or the given schema files.
The output of a generation process may look like:

.. code-block:: none

    Generated class MyApp\User\Response\Login
    Generated class MyApp\User\Response\Register
    Duplicated signature 444fd086d8d1f186145a6f81a3ac3f7a for class Register_Message. Redirecting to Login_Message
    Rendered class MyApp\User\Response\Login
    Rendered class MyApp\User\Response\Register

Custom filter
^^^^^^^^^^^^^

.. code-block:: php

    addFilter(FilterInterface $customFilter);

Add a custom filter to the generator. For more details see `Filter <nonStandardExtensions/filter.html>`__.

Post Processors
---------------

Additionally to the described generator configuration options you can add post processors to your model generator object to change or extend the generated code. For more details see `post processors <generator/postProcessor.html>`__.
