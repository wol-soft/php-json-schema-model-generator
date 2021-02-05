Examples
========

Ebay OpenAPIv3 spec
-------------------

The Ebay OpenAPIv3 spec for the sell-inventory API is an around 6000 lines API definition. Using a script like the example below you can create ~120 PHP classes to handle requests, responses and all nested objects from the API spec:

.. code-block:: php

    $generator = new ModelGenerator((new GeneratorConfiguration())
        ->setNamespacePrefix('Ebay')
        ->setImmutable(false)
    );

    $file = __DIR__ . '/api-definition.json';
    $resultDir = __DIR__ . '/result';

    file_put_contents(
        $file,
        file_get_contents('https://developer.ebay.com/api-docs/master/sell/inventory/openapi/3/sell_inventory_v1_oas3.json')
    );

    $generator
        ->generateModelDirectory($resultDir)
        ->generateModels(new OpenAPIv3Provider($file), $resultDir);

Measured runtime of the script (Pretty printing is disabled) is around 3 seconds at a memory peak consumption between 5 and 6 MB.
