Custom Post Processors
======================

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
* Add PHP attributes to the generated class or to individual properties (see `PHP Attributes`_ below)
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

PHP Attributes
--------------

You can attach PHP 8.0 `attributes <https://www.php.net/manual/en/language.attributes.overview.php>`__ to the generated class and to individual properties using **PHPModelGenerator\\Model\\PhpAttribute**.

**Class-level attribute** (placed before the ``class`` keyword in the generated file):

.. code-block:: php

    use PHPModelGenerator\Model\PhpAttribute;
    use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

    class ORM_EntityPostProcessor extends PostProcessor
    {
        public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
        {
            // No arguments
            $schema->addAttribute(new PhpAttribute(\Doctrine\ORM\Mapping\Entity::class));

            // With positional arguments (pre-rendered PHP expression strings)
            $schema->addAttribute(new PhpAttribute(\Doctrine\ORM\Mapping\Table::class, ["'users'"]));

            // With named arguments
            $schema->addAttribute(new PhpAttribute(
                \Doctrine\ORM\Mapping\Table::class,
                ['name' => "'users'", 'schema' => "'public'"],
            ));
        }
    }

**Property-level attribute** (placed before the property declaration in the generated file):

.. code-block:: php

    use PHPModelGenerator\Model\PhpAttribute;
    use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

    class ORM_ColumnPostProcessor extends PostProcessor
    {
        public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
        {
            foreach ($schema->getProperties() as $property) {
                $property->addAttribute(new PhpAttribute(
                    \Doctrine\ORM\Mapping\Column::class,
                    ['name' => "'" . $property->getName() . "'"],
                ));
            }
        }
    }

The constructor of **PhpAttribute** accepts:

* ``$fqcn`` — the fully-qualified class name of the attribute (e.g. ``'Doctrine\\ORM\\Mapping\\Column'``)
* ``$arguments`` — an optional array of pre-rendered PHP expression strings:

  * **Integer keys** → positional arguments (rendered as-is)
  * **String keys** → named arguments (rendered as ``key: value``)
