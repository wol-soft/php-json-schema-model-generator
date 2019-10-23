Getting started
===============

Installation
------------

The recommended way to install php-json-model-generator is through `Composer <http://getcomposer.org>`_:

.. code-block:: rconsole

    composer require --dev wol-soft/php-json-model-generator
    composer require wol-soft/php-json-model-generator-exception

To avoid adding all dependencies of the php-json-model-generator to your production dependencies it's recommended to add the library as a dev-dependency and include the php-json-model-generator-exception library. The exception library provides all classes to run the generated code. Generating the classes should either be a step done in the development environment (if you decide to commit the models) or as a build step of your application.

Generating classes
------------------

The base object for generating models is the *Generator*. After you have created a Generator you can use the object to generate your model classes without any further configuration:

.. code-block:: php

    (new Generator())
        ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');

As an optional parameter you can set up a *GeneratorConfiguration* object to configure your Generator and/or use the method *generateModelDirectory* to generate your model directory (will generate the directory if it doesn't exist; if it exists, all contained files and folders will be removed for a clean generation process):

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->setNamespacePrefix('\MyApp\Model')
            ->setImmutable(true)
    );

    $generator
        ->generateModelDirectory(__DIR__ . '/result');
        ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');

The generator will check the given source directory recursive and convert all found \*.json files to models. All JSON-Schema files inside the source directory must provide a schema of an object.

Default interface of configured classes
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The constructor of a generated model takes an array as argument. The array must contain the structure defined in the JSON-Schema which is afterwards validated and applied to the state of the model.

.. code-block:: json

    {
        "id": "person",
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
    $person = new Person();

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

Configuring the generator
-------------------------

The *GeneratorConfiguration* object offers methods to configure the generator in a fluid interface.

Namespace prefix
^^^^^^^^^^^^^^^^

.. code-block:: php

    setNamespacePrefix(string $prefix);

Configures a namespace prefix for all generated classes. The namespaces will be extended with the directory structure of the source directory. By default no namespace prefix will be set.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setNamespacePrefix('\MyApp\Model');

Immutable classes
^^^^^^^^^^^^^^^^^

.. code-block:: php

    setImmutable(bool $immutable);

If set to true the generated model classes will be delivered without setter methods for the object properties. By default the classes are generated without setter methods.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setImmutable(false);

Collect errors vs. early return
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setCollectErrors(bool $collectErrors);

By default the complete input is validated and in case of failing validations all error messages will be thrown in a single exception implementing the ErrorRegistryExceptionInterface interface. If set to false the first failing validation will throw an exception.

The exceptions are implemented in the php-json-schema-model-generator-exception repository. Default exceptions:

* Error collection enabled: **PHPModelGeneratorException\ErrorRegistryException**
* Error collection disabled: **PHPModelGeneratorException\ValidationException**

.. code-block:: php

    (new GeneratorConfiguration())
        ->setCollectErrors(false);

Custom exception classes
^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setErrorRegistryClass(string $exceptionClass);
    setExceptionClass(string $exceptionClass);

To set a custom exception thrown if errors occur during validation use *setErrorRegistryClass* if you have enabled error collection, *setExceptionClass* otherwise. The exception provided via *setErrorRegistryClass* must implement the ErrorRegistryExceptionInterface.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setErrorRegistryClass(MyCustomException::class);

Code style of the generated classes
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setPrettyPrint(bool $prettyPrint);

If set to false, the generated model classes won't follow coding guidelines (but the generation is faster). If enabled the package `Symplify/EasyCodingStandard <https://github.com/Symplify/EasyCodingStandard>`_ will be used to clean up the generated code. By default pretty printing is disabled.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setPrettyPrint(true);

Serialization methods
^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setSerialization(bool $serialization);

If set to true the serialization methods `toArray` and `toJSON` will be added to the public interface of the generated classes. By default no serialization methods are added.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setSerialization(true);

Generated interface:

.. code-block:: php

    public function toArray(): array;
    public function toJSON(): string;

Output generation process
^^^^^^^^^^^^^^^^^^^^^^^^^

.. code-block:: php

    setOutputEnabled(bool $outputEnabled);

Enable or disable output of the generation process to STDOUT. By default the output is enabled.

.. code-block:: php

    (new GeneratorConfiguration())
        ->setOutputEnabled(false);
