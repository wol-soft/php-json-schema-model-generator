Post Processor
==============

Post processors provide an easy way to extend your generated code. A post processor can be added to your `ModelGenerator` object:

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new PopulatePostProcessor());

    $files = $generator->generateModelDirectory(__DIR__ . '/result')
        ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');

All added post processors will be executed after a schema was processed and before a model is rendered. Consequently a post processor can be used to change the generated class or to extend the class. Also additional tasks which don't change the rendered code may be executed (eg. create a documentation file for the class, create SQL create statements for tables representing the class, ...).

.. toctree::
    :caption: Builtin Post Processors
    :maxdepth: 1

    builtin/builderClassPostProcessor
    builtin/enumPostProcessor
    builtin/populatePostProcessor
    builtin/additionalPropertiesAccessorPostProcessor
    builtin/patternPropertiesAccessorPostProcessor

.. toctree::
    :caption: Custom Post Processors
    :maxdepth: 1

    custom/customPostProcessor
