<p align="center">
  <a href="https://codeclimate.com/github/wol-soft/php-json-schema-model-generator/maintainability"><img src="https://api.codeclimate.com/v1/badges/7eb29e7366dc3d6a5f44/maintainability" /></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/wol-soft/php-json-schema-model-generator.svg" alt="License"></a>
</p>

# php-json-schema-model-generator
Creates (immutable) PHP model classes from JSON-Schema files.

## Requirements ##

- Requires at least PHP 7.1

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

As an optional parameter you can set up a *GeneratorConfiguration* object to configure your Generator:

```php
$generator = new Generator(
    (new GeneratorConfiguration())
        ->setNamespacePrefix('\\MyApp\\Model')
        ->setImmutable(true)
);

$generator->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
```
## Configuring using the GeneratorConfiguration ##

The *GeneratorConfiguration* object offers the following methods to configure the generator in a fluid interface:

Method | Configuration | Example | Default
--- | --- | --- | ---
``` setNamespacePrefix(string $prefix) ``` | Configures a namespace prefix for all generated classes. The namespaces will be extended with the directory structure of the source directory. | ``` setNamespacePrefix('\\MyApp\\Model') ``` | Empty string so no namespace prefix will be used
``` setImmutable(bool $immutable) ``` | If set to true the generated model classes will be delivered without setter methods for the object properties. | ``` setImmutable(true) ``` | false
``` setPrettyPrint(bool $prettyPrint) ``` | If set to false, the generated model classes won't follow coding gudelines (but the generation is faster). If enabled the package [Symplify/EasyCodingStandard](https://github.com/Symplify/EasyCodingStandard) will be used to clean up the generated code. | ``` setPrettyPrint(false) ``` | true
``` setOutputEnabled(bool $prettyPrint) ``` | Enable or disable output of the generation process to STDOUT | ``` setOutputEnabled(false) ``` | true
