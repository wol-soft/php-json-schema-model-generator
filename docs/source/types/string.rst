String
======

Used for properties containing characters. Converted to the PHP type `string`.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        }
    }

Generated interface:

.. code-block:: php

    public function setExample(string $example): static;
    // As the property is not required it may be initialized with null. Consequently the return value is nullable
    public function getExample(): ?string;

Possible exceptions:

* Invalid type for example. Requires string, got __TYPE__

The thrown exception will be a *PHPModelGenerator\\Exception\\Generic\\InvalidTypeException* which provides the following methods to get further error details:

.. code-block:: php

    // returns a string if the property expects exactly one type, an array if the property accepts multiple types
    public function getExpectedType()
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Length validation
-----------------

To add a length validation to the property use the `minLength` and `maxLength` keywords. The length check is multi byte safe.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "minLength": 3,
                "maxLength": 5
            }
        }
    }

Possible exceptions:

* Value for example must not be shorter than 3
* Value for example must not be longer than 5

The thrown exception will be a *PHPModelGenerator\\Exception\\String\\MinLengthException* or a *PHPModelGenerator\\Exception\\String\\MaxLengthException* which provides the following methods to get further error details:

.. code-block:: php

    // for a MaxLengthException: get the maximum length of the string
    public function getMaximumLength(): int
    // for a MinLengthException: get the minimum length of the string
    public function getMinimumLength(): int
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Pattern validation
------------------

To add a pattern validation to the property use the `pattern` keyword.

.. warning::

    The validation is executed with `preg_match`, consequently PCRE syntax is used instead of ECMA 262.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "pattern": "^[a-zA-Z]*$"
            }
        }
    }

Possible exceptions:

* Value for property doesn't match pattern ^[a-zA-Z]*$

The thrown exception will be a *PHPModelGenerator\\Exception\\String\\PatternException* which provides the following methods to get further error details:

.. code-block:: php

    // get the expected pattern
    public function getExpectedPattern(): string
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Format
------

To add a format validation to the property use the `format` keyword.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string",
                "format": "myFormat"
            }
        }
    }

Possible exceptions:

* Value for property must match the format __FORMAT__

The thrown exception will be a *PHPModelGenerator\\Exception\\String\\FormatException* which provides the following methods to get further error details:

.. code-block:: php

    // get the expected format
    public function getExpectedFormat(): string
    // get the name of the property which failed
    public function getPropertyName(): string
    // get the value provided to the property
    public function getProvidedValue()

Builtin formats
^^^^^^^^^^^^^^^

The following JSON Schema Draft 7 formats are supported out of the box and require no additional configuration:

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Format keyword
     - Description
   * - ``date-time``
     - RFC 3339 date-time (e.g. ``2023-06-15T10:30:00Z``)
   * - ``date``
     - RFC 3339 full-date (e.g. ``2023-06-15``)
   * - ``time``
     - RFC 3339 full-time with timezone (e.g. ``10:30:00Z``)
   * - ``email``
     - RFC 5322 e-mail address (simplified)
   * - ``idn-email``
     - Internationalized e-mail address
   * - ``hostname``
     - RFC 1123 Internet host name
   * - ``idn-hostname``
     - Internationalized host name
   * - ``ipv4``
     - IPv4 address (dotted-decimal notation)
   * - ``ipv6``
     - IPv6 address
   * - ``uri``
     - RFC 3986 absolute URI (scheme required)
   * - ``uri-reference``
     - RFC 3986 URI-reference (absolute or relative)
   * - ``uri-template``
     - RFC 6570 URI template
   * - ``iri``
     - RFC 3987 absolute IRI (URI extended with Unicode)
   * - ``iri-reference``
     - RFC 3987 IRI-reference
   * - ``json-pointer``
     - RFC 6901 JSON Pointer (e.g. ``/foo/bar``)
   * - ``relative-json-pointer``
     - Relative JSON Pointer (e.g. ``1/foo``)
   * - ``regex``
     - PCRE-compatible regular expression

Built-in formats can be overridden by calling ``addFormat`` with the same key on the ``GeneratorConfiguration``.

Custom formats
^^^^^^^^^^^^^^

You can implement custom format validators and use them in your schema files. You must add your custom format to the generator configuration to make them available.

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->addFormat('customFormat', new MyCustomFormat())
    );

Your format validator must implement the interface **PHPModelGenerator\\Format\\FormatValidatorInterface**.

If your custom format is representable by a regular expression you can bypass implementing an own class and simply add a **FormatValidatorFromRegEx** (for example a string which must contain only numbers):

.. code-block:: php

    $generator = new Generator(
        (new GeneratorConfiguration())
            ->addFormat('numeric', new FormatValidatorFromRegEx('/^\d*$/'))
    );

.. hint::

    Pull requests for common usable format validators are always welcome.
    A new format validator must be added in the *GeneratorConfiguration* method *initFormatValidator*.
    If the format validator requires a class implementation and can't be added via the *FormatValidatorFromRegEx* the class must be added to the *wol-soft/php-json-schema-model-generator-production* repository.
