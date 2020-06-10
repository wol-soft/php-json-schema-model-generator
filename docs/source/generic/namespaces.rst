Namespaces
==========

If a directory with subdirectories is converted into models the directory structure of the JSON-Schema files will be mirrored to the directory structure of the generated models.
Models located in a subdirectory will contain a PSR-4 compatible namespace based on the directory structure.
Set a namespace prefix with the `setNamespacePrefix` method of the `GeneratorConfiguration` object for locating the models inside your application or adding them to your composer autoloading configuration.

An example structure of your JSON-Schema files for a user module may look like:

.. code-block:: none

    - Models
        - Request
            - Login.json
            - Register.json
            - Update.json
        - Response
            - Error
                - UserExists.json
            - Login.json
            - Register.json
            - Update.json
    - Modules
        - LoginData.json
        - User.json
        - Message.json
    - generateModels.php

Your model generation code inside `generateModels.php` now could look like:

.. code-block:: php

    $generator = new ModelGenerator((new GeneratorConfiguration())->setNamespacePrefix('MyApp\User'));

    $generator
        ->generateModelDirectory(__DIR__ . '/build')
        ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/Models'), __DIR__ . '/build');

The generated main classes will be:

.. code-block:: none

    - build
        - Request
            - Login.php (FQCN: `MyApp\User\Request\Login`)
            - Register.php (FQCN: `MyApp\User\Request\Register`)
            - Update.php (FQCN: `MyApp\User\Request\Update`)
        - Response
            - Error
                - UserExists.php (FQCN: `MyApp\User\Response\Error\UserExists`)
            - Login.php (FQCN: `MyApp\User\Response\Login`)
            - Register.php (FQCN: `MyApp\User\Response\Register`)
            - Update.php (FQCN: `MyApp\User\Response\Update`)

Class re-usage
--------------

If referenced classes (eg. in the example given above the modules which may be used in multiple schemas) or nested classes occur multiple times the generator will detect the re-used class and link to the already generated object.
The generator gives a `duplicated signature` hint in the output.

The detection is not bound to namespace limits so a nested object which occurs in `Request\Register.json` as well as in `Response\Register.json` will be generated only once.
Consequently the class generated to `Response\Register.php` may use classes from the `Request` namespace.
A duplicated class is not linked to an already generated class if it's a primary object. The detection only links nested classes.
