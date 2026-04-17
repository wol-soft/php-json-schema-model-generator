Custom Drafts
=============

The *Draft* system defines which JSON Schema keywords are recognised during model generation and
how each keyword is translated into PHP code.  Every ``GeneratorConfiguration`` has a
*draft* — by default ``AutoDetectionDraft``, which inspects the ``$schema`` keyword in each
schema file and picks the appropriate draft automatically.

You can replace the default draft with a concrete ``DraftInterface`` implementation to pin all
schemas to a single set of rules, or supply a ``DraftFactoryInterface`` implementation to choose
the draft dynamically per schema file.

Concepts
--------

Type
    A named JSON Schema type (``"object"``, ``"string"``, ``"integer"``, ``"number"``,
    ``"boolean"``, ``"array"``, ``"null"``, or the virtual ``"any"`` type that applies to every
    property regardless of its declared type).

    Each type entry holds an ordered list of *modifiers*.

Modifier (``ModifierInterface``)
    A unit of work that reads the raw JSON Schema for a property and modifies the in-memory
    ``PropertyInterface`` object — adding validators, decorators, type hints, or any other
    enrichment.  Modifiers are executed in registration order, and the ``"any"`` modifiers run
    for every property.

Validator factory (``AbstractValidatorFactory``)
    A special modifier that is keyed to a single JSON Schema keyword (e.g. ``"minLength"``).
    It checks whether that keyword is present in the schema and, if so, adds the corresponding
    validator to the property.  Validator factories are registered via ``Type::addValidator()``.

Implementing a custom draft
---------------------------

The simplest approach is to extend an existing draft (e.g. ``Draft_07``) via the builder API and
override the parts you need.

**Example: add a custom keyword modifier to Draft 7**

.. code-block:: php

    use PHPModelGenerator\Draft\Draft_07;
    use PHPModelGenerator\Draft\DraftBuilder;
    use PHPModelGenerator\Draft\DraftInterface;
    use PHPModelGenerator\Draft\Modifier\ModifierInterface;
    use PHPModelGenerator\Model\Property\PropertyInterface;
    use PHPModelGenerator\Model\Schema;
    use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
    use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

    class DeprecatedModifier implements ModifierInterface
    {
        public function modify(
            SchemaProcessor $schemaProcessor,
            Schema $schema,
            PropertyInterface $property,
            JsonSchema $propertySchema,
        ): void {
            if (!($propertySchema->getJson()['deprecated'] ?? false)) {
                return;
            }

            // Add a PHPDoc annotation or any other enrichment here.
        }
    }

    class MyDraft implements DraftInterface
    {
        public function getDefinition(): DraftBuilder
        {
            // Obtain the standard Draft 7 builder and append a modifier to the 'any' type.
            $builder = (new Draft_07())->getDefinition();
            $builder->getType('any')->addModifier(new DeprecatedModifier());

            return $builder;
        }
    }

Register the custom draft in your generator configuration:

.. code-block:: php

    use PHPModelGenerator\Model\GeneratorConfiguration;

    $configuration = (new GeneratorConfiguration())
        ->setDraft(new MyDraft());

Implementing a draft factory
-----------------------------

If you need to select the draft dynamically per schema file, implement
``DraftFactoryInterface``:

.. code-block:: php

    use PHPModelGenerator\Draft\AutoDetectionDraft;
    use PHPModelGenerator\Draft\DraftFactoryInterface;
    use PHPModelGenerator\Draft\DraftInterface;
    use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

    class MyDraftFactory implements DraftFactoryInterface
    {
        private AutoDetectionDraft $autoDetect;
        private MyDraft $myDraft;

        public function __construct()
        {
            $this->autoDetect = new AutoDetectionDraft();
            $this->myDraft    = new MyDraft();
        }

        public function getDraftForSchema(JsonSchema $schema): DraftInterface
        {
            // Use the custom draft only for schemas that opt in via a custom flag.
            if ($schema->getJson()['x-use-my-draft'] ?? false) {
                return $this->myDraft;
            }

            return $this->autoDetect->getDraftForSchema($schema);
        }
    }

    $configuration = (new GeneratorConfiguration())
        ->setDraft(new MyDraftFactory());
