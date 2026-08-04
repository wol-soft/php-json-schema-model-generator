Test infrastructure
===================

The library is tested with PHPUnit using atomic integration tests. Each test generates one or more PHP model classes from a JSON Schema string or file, instantiates those classes, and asserts on the resulting validation behaviour and generated type signatures. There are no unit tests that stub out internal components — every test exercises the full generation pipeline end-to-end.

Test output directories
-----------------------

During a test run two directories are used automatically:

* **Temp directory** — generated JSON Schema files and PHP classes are written to ``{sys_get_temp_dir()}/PHPModelGeneratorTest/Models/``. Each test method gets its own uniquely-named subdirectory so parallel runs do not collide.
* ``./failed-classes/`` — when a test fails, all JSON Schema files and the generated PHP classes produced by that test are copied here for post-mortem inspection. The directory is cleaned automatically on bootstrap.

Running the tests
-----------------

Install dependencies first:

.. code-block:: console

    composer update

Run the full suite:

.. code-block:: console

    ./vendor/bin/phpunit

For development work, save the full output to a file so it can be inspected after the run without re-executing the suite:

.. code-block:: console

    php -d memory_limit=128M ./vendor/bin/phpunit --no-coverage --display-warnings 2>&1 \
        | sed 's/\x1b\[[0-9;]*m//g' > /tmp/phpunit-output.txt; tail -5 /tmp/phpunit-output.txt

Then analyse the output with:

.. code-block:: console

    grep -E "FAIL|ERROR|WARN|Tests:" /tmp/phpunit-output.txt

Run a single file or a single method:

.. code-block:: console

    ./vendor/bin/phpunit tests/Basic/BasicSchemaGenerationTest.php
    ./vendor/bin/phpunit --filter testGetterAndSetterAreGeneratedForMutableObjects

Base test class
---------------

All test classes extend ``AbstractPHPModelGeneratorTestCase``. The base class provides:

**Class generation methods**

* ``generateClassFromFile(string $file, ?GeneratorConfiguration $config, ...)`` — generates model classes from a JSON Schema file located under ``tests/Schema/``.
* ``generateClass(string $jsonSchema, ?GeneratorConfiguration $config, ...)`` — generates from a raw JSON Schema string.
* ``generateClassFromFileTemplate(string $file, array $values, ...)`` — generates from a schema file treated as a ``sprintf`` template.
* ``generateDirectory(string $directory, GeneratorConfiguration $configuration)`` — generates all schemas in a directory.

All generation methods automatically apply the active JSON Schema draft when the test is running as part of a multi-draft expansion (see below).

**Assertion helpers**

* ``expectValidationError(GeneratorConfiguration $config, array|string $messages)`` — asserts that the provided callable throws a ``ValidationException`` or ``ErrorRegistryException`` containing the expected messages, depending on whether error collection is enabled in the configuration.
* ``expectValidationErrorRegExp(...)`` — regex variant of the above.
* ``assertErrorRegistryContainsException(...)`` — locates a specific exception type within an ``ErrorRegistryException``.
* ``assertClassHasJsonPointer()``, ``assertPropertyHasJsonPointer()`` — verify ``JsonPointer`` PHP attributes on generated classes and properties.

**Reflection helpers**

``getReturnTypeNames()``, ``getParameterTypeNames()``, ``getPropertyTypeNames()`` and their non-plural counterparts extract native PHP type hints from generated classes via reflection. ``get*TypeAnnotation()`` variants extract types from docblock annotations.

**Built-in data providers**

* ``validationMethodDataProvider()`` — yields two ``GeneratorConfiguration`` instances: one with direct exception throwing and one with error collection. Use this provider for tests that must pass under both error-handling modes.
* ``implicitNullDataProvider()`` — yields configurations with implicit null enabled and disabled.
* ``namespaceDataProvider()`` — yields no-namespace and custom-namespace variants.
* ``combineDataProvider(array $a, array $b)`` — returns the Cartesian product of two data providers.

JSON Schema fixtures are stored under ``tests/Schema/`` in subdirectories named after the test class (e.g. ``tests/Schema/ArrayContainsTest/``).

Multi-draft testing
-------------------

The library supports JSON Schema Draft 7, Draft 2019-09, and Draft 2020-12. The multi-draft test infrastructure allows a single test method to be executed once per applicable draft without duplicating test code.

The system is implemented as a PHPUnit extension (``DraftExpansionExtension``) that intercepts metadata at collection time and expands annotated test methods into multiple test entries — one per applicable draft. The correct draft is then injected into the generation methods automatically via ``DraftRunContext``.

The ``ApplicableDrafts`` attribute
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Mark a test class or test method with the ``#[ApplicableDrafts]`` attribute to opt it into multi-draft expansion:

.. code-block:: php

    use PHPModelGenerator\Tests\Support\ApplicableDrafts;
    use PHPModelGenerator\Draft\JsonSchemaDraft;

    #[ApplicableDrafts]
    class BasicSchemaGenerationTest extends AbstractPHPModelGeneratorTestCase
    {
        public function testSomeBehavior(): void { ... }
    }

A class-level attribute applies to every method in the class unless a method-level attribute overrides it. A method-level attribute takes precedence over the class-level attribute.

The attribute accepts two optional parameters that define the inclusive draft range:

* ``from: JsonSchemaDraft`` — the earliest draft for which the test is applicable (default: ``DRAFT_07``).
* ``until: JsonSchemaDraft`` — the latest draft for which the test is applicable (default: the newest known draft).

Examples:

.. code-block:: php

    // Applicable to Draft 2019-09 and later only
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testMinContains(GeneratorConfiguration $config): void { ... }

    // Applicable to Draft 7 only
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testDraft07SpecificBehavior(): void { ... }

    // Applicable to Draft 2019-09 through Draft 2020-12 (explicit range)
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09, until: JsonSchemaDraft::DRAFT_2020_12)]
    public function testSomeDraftRangeBehavior(): void { ... }

The three available drafts are defined in the ``JsonSchemaDraft`` enum:

=========================== ===========
Constant                    Description
=========================== ===========
``JsonSchemaDraft::DRAFT_07``         JSON Schema Draft 7 (the baseline)
``JsonSchemaDraft::DRAFT_2019_09``    JSON Schema Draft 2019-09
``JsonSchemaDraft::DRAFT_2020_12``    JSON Schema Draft 2020-12
=========================== ===========

Data providers and multi-draft expansion
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

When a multi-draft test method also uses a ``#[DataProvider]``, the extension creates the Cartesian product of drafts × data-provider entries. Each resulting test case receives a composite data name such as ``Draft 2019-09 / #0`` that encodes both the draft and the original data-set index.

Run modes
---------

The test suite supports three run modes controlled by environment variables.

Quick mode (default)
^^^^^^^^^^^^^^^^^^^^

Without any environment variables, each annotated test method runs exactly once using only the **latest applicable draft** in its range. This keeps the suite fast during normal development.

.. code-block:: console

    ./vendor/bin/phpunit

Full draft coverage mode
^^^^^^^^^^^^^^^^^^^^^^^^

Set ``PHPUNIT_FULL_DRAFT_COVERAGE=1`` to run every annotated test method once per applicable draft. Use this before merging to verify that behaviour is consistent across all supported drafts.

.. code-block:: console

    PHPUNIT_FULL_DRAFT_COVERAGE=1 ./vendor/bin/phpunit

Specific draft mode
^^^^^^^^^^^^^^^^^^^

Set ``PHPUNIT_DRAFT=<hint>`` to restrict the run to a single draft. The hint is matched case-insensitively after stripping all non-alphanumeric characters, so ``2019``, ``201909``, ``Draft2019``, and ``draft-2019-09`` all resolve to ``DRAFT_2019_09``. Test methods whose applicable range does not include the requested draft are skipped automatically.

.. code-block:: console

    PHPUNIT_DRAFT=2019 ./vendor/bin/phpunit
    PHPUNIT_DRAFT=Draft202012 ./vendor/bin/phpunit

Writing multi-draft tests
--------------------------

Follow these steps when adding a new test that should run against multiple drafts:

1. Add the ``#[ApplicableDrafts]`` attribute to the test class (for suite-wide applicability) or to individual methods (for narrower ranges).
2. Do **not** pass a draft to ``GeneratorConfiguration`` manually — the base class reads it from ``DraftRunContext`` and applies it automatically.
3. If a feature only exists from a specific draft onward, use ``#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]`` on that method rather than adding a ``if`` branch inside the test body.
4. For behaviour that is deliberately different across drafts, write separate test methods — one per relevant draft range — rather than a single method with conditional logic.
