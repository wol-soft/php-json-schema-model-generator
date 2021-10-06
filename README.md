[![Latest Version](https://img.shields.io/packagist/v/wol-soft/php-json-schema-model-generator.svg)](https://packagist.org/packages/wol-soft/php-json-schema-model-generator)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.2-8892BF.svg)](https://php.net/)
[![Maintainability](https://api.codeclimate.com/v1/badges/7eb29e7366dc3d6a5f44/maintainability)](https://codeclimate.com/github/wol-soft/php-json-schema-model-generator/maintainability)
[![Build Status](https://github.com/wol-soft/php-json-schema-model-generator/actions/workflows/main.yml/badge.svg)](https://github.com/wol-soft/php-json-schema-model-generator/actions/workflows/main.yml)
[![Coverage Status](https://coveralls.io/repos/github/wol-soft/php-json-schema-model-generator/badge.svg?branch=master)](https://coveralls.io/github/wol-soft/php-json-schema-model-generator?branch=master)
[![MIT License](https://img.shields.io/packagist/l/wol-soft/php-json-schema-model-generator.svg)](https://github.com/wol-soft/php-json-schema-model-generator/blob/master/LICENSE)
[![Documentation Status](https://readthedocs.org/projects/php-json-schema-model-generator/badge/?version=latest)](https://php-json-schema-model-generator.readthedocs.io/en/latest/?badge=latest)

# php-json-schema-model-generator
Generates PHP model classes from JSON-Schema files including validation and providing a fluent auto completion for the generated classes.

## Table of Contents ##

* [Motivation](#Motivation)
* [Requirements](#Requirements)
* [Installation](#Installation)
* [Basic usage](#Basic-usage)
* [Configuring using the GeneratorConfiguration](#Configuring-using-the-GeneratorConfiguration)
* [Examples](#Examples)
* [How the heck does this work?](#How-the-heck-does-this-work)
* [Tests](#Tests)
* [Docs](#Docs)

## Motivation ##

Simple example from a PHP application: you define and document an API with swagger annotations and JSON-Schema models. Now you want to use models in your controller actions instead of manually accessing the request data (eg. array stuff). Additionally your schema already defines the validation rules for the models. Why duplicate this rules into your manually written code? Instead you can set up a middleware which instantiates models generated with this library and feed the model with the request data. Now you have a validated model which you can use in your controller action. With full auto completion when working with nested objects. Yay!

## Requirements ##

- Requires at least PHP 7.2
- Requires the PHP extensions ext-json and ext-mbstring

## Installation ##

The recommended way to install php-json-schema-model-generator is through [Composer](http://getcomposer.org):
```
$ composer require --dev wol-soft/php-json-schema-model-generator
$ composer require wol-soft/php-json-schema-model-generator-production
```

To avoid adding all dependencies of the php-json-schema-model-generator to your production dependencies it's recommended to add the library as a dev-dependency and include the [wol-soft/php-json-schema-model-generator-production](https://github.com/wol-soft/php-json-schema-model-generator-production) library. The production library provides all classes to run the generated code. Generating the classes should either be a step done in the development environment or as a build step of your application (which is the recommended workflow).

## Basic usage ##

Check out the [docs](https://php-json-schema-model-generator.readthedocs.io/en/latest/) for more details.

The base object for generating models is the *Generator*. After you have created a Generator you can use the object to generate your model classes without any further configuration:

```php
(new Generator())
    ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');
```
The first parameter of the *generateModels* method must be a class implementing the *SchemaProviderInterface*. The provider fetches the JSON schema files and provides them for the generator. The following providers are available:

Provider | Description
--- | ---
RecursiveDirectoryProvider | Fetches all *.json files from the given source directory. Each file must contain a JSON Schema object definition on the top level
OpenAPIv3Provider | Fetches all objects defined in the `#/components/schemas section` of an Open API v3 spec file

The second parameter must point to an existing and empty directory (you may use the `generateModelDirectory` helper method to create your destination directory). This directory will contain the generated PHP classes after the generator is finished.

As an optional parameter you can set up a *GeneratorConfiguration* object (check out the docs for all available options) to configure your Generator and/or use the method *generateModelDirectory* to generate your model directory (will generate the directory if it doesn't exist; if it exists, all contained files and folders will be removed for a clean generation process):

```php
$generator = new Generator(
    (new GeneratorConfiguration())
        ->setNamespacePrefix('MyApp\Model')
        ->setImmutable(false)
);

$generator
    ->generateModelDirectory(__DIR__ . '/result');
    ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');
```

The generator will check the given source directory recursive and convert all found *.json files to models. All JSON-Schema files inside the source directory must provide a schema of an object.

## Examples ##

The directory `./tests/manual` contains some easy examples which show the usage. After installing the dependencies of the library via `composer update` you can execute `php ./tests/manual/test.php` to generate the examples and play around with some JSON-Schema files to explore the library.

Let's have a look into an easy example. We create a simple model for a person with a name and an optional age. Our resulting JSON-Schema:
```json
{
  "$id": "Person",
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
```

After generating a class with this JSON-Schema our class with the name `Person` will provide the following interface:
```php
// the constructor takes an array with data which is validated and applied to the model
public function __construct(array $modelData);

// the method getRawModelDataInput always delivers the raw input which was provided on instantiation
public function getRawModelDataInput(): array;

// getters to fetch the validated properties. Age is nullable as it's not required
public function getName(): string;
public function getAge(): ?int;

// setters to change the values of the model after instantiation (only generated if immutability is disabled)
public function setName(string $name): Person;
public function setAge(?int $age): Person;
```

Now let's have a look at the behaviour of the generated model:
```php
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
```

More complex exception messages eg. from a [allOf](https://json-schema.org/understanding-json-schema/reference/combining.html#allof) composition may look like:
```
Invalid value for Animal declined by composition constraint.
  Requires to match 3 composition elements but matched 1 elements.
  - Composition element #1: Failed
    * Value for age must not be smaller than 0
  - Composition element #2: Valid
  - Composition element #3: Failed
    * Value for legs must not be smaller than 2
    * Value for legs must be a multiple of 2
```

## How the heck does this work? ##

The class generation process basically splits up into three to four steps:

- Scan the given source directory to find all *.json files which should be processed.
- Loop over all schemas which should be generated. This is the main step of the class generation. Now each schema is parsed and a Schema model class which holds the properties for the generated model is populated. All validation rules defined in the JSON-Schema are translated into plain PHP code. After the model is finished a RenderJob is generated and added to the RenderQueue. If a JSON-Schema contains nested objects or references multiple RenderJobs may be added to the RenderQueue for a given schema file.
- If post processors are defined for the generation process the post processors will be applied.
- After all schema files have been parsed without an error the RenderQueue will be worked off. All previous added RenderJobs will be executed and the PHP classes will be saved to the filesystem at the given destination directory.

## Tests ##

The library is tested via [PHPUnit](https://phpunit.de/).

After installing the dependencies of the library via `composer update` you can execute the tests with `./vendor/bin/phpunit` (Linux) or `vendor\bin\phpunit.bat` (Windows). The test names are optimized for the usage of the `--testdox` output. Most tests are atomic integration tests which will set up a JSON-Schema file and generate a class from the schema and test the behaviour of the generated class afterwards.

During the execution the tests will create a directory PHPModelGeneratorTest in tmp where JSON-Schema files and PHP classes will be written to.

If a test which creates a PHP class from a JSON-Schema fails the JSON-Schema and the generated class(es) will be dumped to the directory `./failed-classes`

## Docs ##

The [docs](https://php-json-schema-model-generator.readthedocs.io/en/latest/) for the library is generated with [Sphinx](https://www.sphinx-doc.org/en/master/).

To generate the documentation install Sphinx, enter the docs directory and execute `make html` (Linux) or `make.bat html` (Windows). The generated documentation will be available in the directory `./docs/build`.

The documentation hosted at [Read the Docs](https://readthedocs.org/) is updated on each push.
