[![Latest Version](https://img.shields.io/packagist/v/wol-soft/php-json-schema-model-generator.svg)](https://packagist.org/packages/wol-soft/php-json-schema-model-generator)
[![Maintainability](https://api.codeclimate.com/v1/badges/9e3c565c528edb3d58d5/maintainability)](https://codeclimate.com/github/wol-soft/php-json-schema-model-generator/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/7eb29e7366dc3d6a5f44/test_coverage)](https://codeclimate.com/github/wol-soft/php-json-schema-model-generator/test_coverage)
[![Build Status](https://travis-ci.org/wol-soft/php-micro-template.svg?branch=master)](https://travis-ci.org/wol-soft/php-json-schema-model-generator)
[![Coverage Status](https://coveralls.io/repos/github/wol-soft/php-json-schema-model-generator/badge.svg?branch=master)](https://coveralls.io/github/wol-soft/php-json-schema-model-generator?branch=master)
[![MIT License](https://img.shields.io/packagist/l/wol-soft/php-micro-template.svg)](https://github.com/wol-soft/php-json-schema-model-generator/blob/master/LICENSE)

# php-json-schema-model-generator
Creates (immutable) PHP model classes from JSON-Schema files.

## Motivation ##

Simple example from a PHP application: you define and document an API with swagger annotations and JSON-Schema models. Now you want to use models in your controller actions instead of manually accessing the request data (eg. array stuff). Additionally your schema already defines the validation rules for the models. Why duplicate this rules into your manually written code? Instead you can set up a middleware which instantiates models generated with this library and feed the model with the request data. Now you have a validated model which you can use in your controller action. With full auto completion when working with nested objects. Yay!

## Requirements ##

- Requires at least PHP 7.2

## Installation ##

The recommended way to install php-json-model-generator is through [Composer](http://getcomposer.org):
```
$ composer require wol-soft/php-json-model-generator
```

## Examples ##

The base object for generating models is the *Generator*. After you have created a Generator you can use the object to generate your model classes without any further configuration:

```php
(new Generator())
    ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
```

As an optional parameter you can set up a *GeneratorConfiguration* object to configure your Generator and/or use the method *generateModelDirectory* to generate your model directory (will generate the directory if it doesn't exist; if it exists, all contained files and folders will be removed for a clean generation process):

```php
$generator = new Generator(
    (new GeneratorConfiguration())
        ->setNamespacePrefix('\\MyApp\\Model')
        ->setImmutable(true)
);

$generator
    ->generateModelDirectory(__DIR__ . '/result');
    ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
```

The generator will check the given source directory recursive and convert all found *.json files to models. All JSON-Schema files inside the source directory must provide a schema of an object.
## Configuring using the GeneratorConfiguration ##

The *GeneratorConfiguration* object offers the following methods to configure the generator in a fluid interface:

Method | Configuration | Example | Default
--- | --- | --- | ---
``` setNamespacePrefix(string $prefix) ``` <br><br>Example:<br> ``` setNamespacePrefix('\MyApp\Model') ``` | Configures a namespace prefix for all generated classes. The namespaces will be extended with the directory structure of the source directory. | Empty string so no namespace prefix will be used
``` setImmutable(bool $immutable) ``` <br><br>Example:<br> ``` setImmutable(false) ``` | If set to true the generated model classes will be delivered without setter methods for the object properties. | true
``` setCollectErrors(bool $collectErrors) ``` <br><br>Example:<br> ``` setCollectErrors(false) ``` | By default the complete input is validated and in case of failing validations all error messages will be thrown in a single exception. If set to false the first failing validation will throw an exception. | true
``` setPrettyPrint(bool $prettyPrint) ``` <br><br>Example:<br> ``` setPrettyPrint(false) ``` | If set to false, the generated model classes won't follow coding gudelines (but the generation is faster). If enabled the package [Symplify/EasyCodingStandard](https://github.com/Symplify/EasyCodingStandard) will be used to clean up the generated code. | true
``` setOutputEnabled(bool $prettyPrint) ``` <br><br>Example:<br> ``` setOutputEnabled(false) ``` | Enable or disable output of the generation process to STDOUT | true
``` setErrorRegistryClass(string $exceptionClass) ``` <br><br>Example:<br> ``` setErrorRegistryClass(CustomException::class) ``` | Define a custom exception implementing the ErrorRegistryExceptionInterface to decouple the generated code from the library (if you want to declare the library as a dev-dependency). The exception will be thrown if a validation fails error collection is **enabled** | ErrorRegistryException::class
``` setExceptionClass(bool $prettyPrint) ``` <br><br>Example:<br> ``` setExceptionClass(CustomException::class) ``` | Define a custom exception to decouple the generated code from the library (if you want to declare the library as a dev-dependency). The exception will be thrown if a validation fails error collection is **disabled** | ValidationException::class

## How the heck does this work? ##

The class generation process basically splits up into three to four steps:

- Scan the given source directory to find all *.json files which should be processed.
- Loop over all schemas which should be generated. This is the main step of the class generation. Now each schema is parsed and a Schema model class which holds the properties for the generated model is populated. After the model is finished a RenderJob is generated and added to the RenderQueue. If a JSON-Schema contains nested objects or references multiple RenderJobs may be added to the RenderQueue for a given schema file.
- After all schema files have been parsed without an error the RenderQueue will be worked off. All previous added RenderJobs will be executed and the PHP classes will be saved to the filesystem at the given destination directory.
- If pretty printing is enabled the generated PHP classes will be cleaned up for a better code formatting. Done.

## Tests ##

After installing the dependencies of the library via `composer update` you can execute the tests with `./vendor/bin/phpunit` (Linux) or `vendor\bin\phpunit.bat` (Windows). The test names are optimized for the usage of the `--testdox` output. Most tests are atomic integration tests which will set up a JSON-Schema file and generate a class from the schema and test the behaviour of the generated class afterwards.

During the execution the tests will create a directory PHPModelGeneratorTest in tmp where JSON-Schema files and PHP classes will be written to.

If a test which creates a PHP class from a JSON-Schema fails the JSON-Schema and the generated class(es) will be dumped to `./Failed-classes`
