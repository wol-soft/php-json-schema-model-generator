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

Content type and encoding
-------------------------

The ``contentMediaType`` and ``contentEncoding`` keywords annotate a string property with its
MIME type and encoding. When either keyword is present, the generated property type changes from
``string`` to a typed wrapper object that carries both the raw value and the schema-defined
metadata.

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "avatar": {
                "type": "string",
                "contentMediaType": "image/png",
                "contentEncoding": "base64"
            }
        }
    }

The wrapper class is selected at code generation time based on the property's mutability:

.. list-table::
   :header-rows: 1
   :widths: 40 60

   * - Property state
     - Wrapper class
   * - Normal (mutable)
     - ``PHPModelGenerator\ValueObject\MediaString``
   * - ``readOnly: true``
     - ``PHPModelGenerator\ValueObject\ImmutableMediaString``
   * - Global immutability active
     - ``PHPModelGenerator\ValueObject\ImmutableMediaString``
   * - ``writeOnly: true``
     - ``PHPModelGenerator\ValueObject\ImmutableMediaString``

Both wrapper classes implement ``Stringable`` and expose:

.. code-block:: php

    public function getValue(): string;
    public function getMediaType(): ?string;
    public function getEncoding(): ?string;
    public function __toString(): string; // returns getValue()

``MediaString`` additionally provides:

.. code-block:: php

    public function setValue(string $value): static;

Generated interface for a mutable property:

.. code-block:: php

    // Setter accepts a raw string or a pre-existing MediaString
    public function setAvatar(string | MediaString $avatar): static;
    // Getter returns the wrapped value (or null when not set and not required)
    public function getAvatar(): ?MediaString;

The schema-defined ``contentMediaType`` and ``contentEncoding`` values are attached to the
wrapper at construction time:

.. code-block:: php

    $object->setAvatar('iVBORw0KGgoAAAANSUhEUgAA…');
    $mediaString = $object->getAvatar(); // MediaString instance
    echo $mediaString->getMediaType();   // "image/png"
    echo $mediaString->getEncoding();    // "base64"
    echo $mediaString->getValue();       // raw base64 string

Passing a pre-existing ``MediaString`` instance to the setter passes it through unchanged,
provided its ``mediaType`` and ``encoding`` match the schema-declared values. A mismatch throws
an ``InvalidFilterValueException``:

.. code-block:: php

    $existing = new MediaString('iVBORw0K…', 'image/png', 'base64');
    $object->setAvatar($existing); // same instance stored, no re-wrapping

    $wrong = new MediaString('data', 'text/plain'); // wrong mediaType
    $object->setAvatar($wrong); // throws InvalidFilterValueException

When ``contentMediaType`` or ``contentEncoding`` is combined with ``format``, format validation
runs on the raw string value before the wrapper is applied. Pre-existing wrapper objects bypass
the format check.

Content validation
^^^^^^^^^^^^^^^^^^

``contentMediaType`` and ``contentEncoding`` are annotations in Draft 7 — no validation is
required. The generator provides an opt-in *content validator registry*: register a validator for
a media type / encoding combination and it will be called against the raw string at runtime.

Implement ``PHPModelGenerator\MediaString\ContentValidatorInterface``. Return without throwing to
signal success; throw any ``\Throwable`` to signal failure:

.. code-block:: php

    use PHPModelGenerator\MediaString\ContentValidatorInterface;

    class Base64JsonValidator implements ContentValidatorInterface
    {
        public static function validate(string $value): void
        {
            $decoded = base64_decode($value, strict: true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Value is not valid base64');
            }
            if (json_decode($decoded) === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Decoded value is not valid JSON');
            }
        }
    }

Register via ``GeneratorConfiguration::addContentValidator``. Each dimension accepts ``null``
(wildcard), a ``string`` (exact match), or a ``string[]`` (the validator is registered for every
combination in the Cartesian product):

.. code-block:: php

    (new GeneratorConfiguration())
        // exact match
        ->addContentValidator('application/json', 'base64', new Base64JsonValidator())
        // any encoding, three media types at once
        ->addContentValidator(['image/png', 'image/jpeg', 'image/webp'], null, new MyImageValidator())
        // full wildcard — matches every content property
        ->addContentValidator(null, null, new MyUniversalValidator());

At code-generation time the generator picks the single most specific registered validator for each
property. Resolution order: exact match → media-type wildcard → encoding wildcard → full wildcard.
If nothing matches, no content check is generated.

**Runtime behaviour**

* The validator receives the **raw string** before the ``MediaString`` wrapper is applied.
* ``null`` values and pre-existing wrapper objects passed to a setter bypass the validator.
* On failure a ``PHPModelGenerator\Exception\String\ContentException`` is thrown; the original
  exception is available via ``getPrevious()``:

.. code-block:: php

    try {
        $object->setAvatar('not-valid-base64');
    } catch (\PHPModelGenerator\Exception\String\ContentException $e) {
        echo $e->getExpectedMediaType(); // "image/png"
        echo $e->getExpectedEncoding(); // "base64"
        echo $e->getPrevious()->getMessage(); // original validator message
    }

The ``ContentException`` exposes:

.. code-block:: php

    public function getExpectedMediaType(): ?string
    public function getExpectedEncoding(): ?string
    public function getPropertyName(): string
    public function getProvidedValue()
