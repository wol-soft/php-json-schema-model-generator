EnumPostProcessor
=================

.. warning::

    Requires at least PHP 8.1

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new EnumPostProcessor(__DIR__ . '/generated/enum/', '\\MyApp\\Enum'));

The **EnumPostProcessor** generates a `PHP enum <https://www.php.net/manual/en/language.enumerations.basics.php>`_ for each `enum <../../complexTypes/enum.html>`__ found in the processed schemas.
Enums which contain only integer values or only string values will be rendered into a `backed enum <https://www.php.net/manual/en/language.enumerations.backed.php>`_.
Other enums will provide the following interface similar to the capabilities of a backed enum:

.. code-block:: php

    public static function from(mixed $value): self;
    public static function tryFrom(mixed $value): ?self;

    public function value(): mixed;

Let's have a look at the most simple case of a string-only enum:

.. code-block:: json

    {
        "$id": "offer",
        "type": "object",
        "properties": {
            "state": {
                "enum": ["open", "sold", "cancelled"]
            }
        }
    }

The provided schema will generate the following enum:

.. code-block:: php

    enum OfferState: string {
        case Open = 'open';
        case Sold = 'sold';
        case Cancelled = 'cancelled';
    }

The type hints and annotations of the generated class will be changed to match the generated enum:

.. code-block:: php

    /**
     * @param OfferState|string|null $state
     */
    public function setState($state): self;
    public function getState(): ?OfferState;

Mapping
~~~~~~~

Each enum which is not a string-only enum must provide a mapping in the **enum-map** property, for example an integer-only enum:

.. code-block:: json

    {
        "$id": "offer",
        "type": "object",
        "properties": {
            "state": {
                "enum": [0, 1, 2],
                "enum-map": {
                    "open": 0,
                    "sold": 1,
                    "cancelled": 2
                }
            }
        }
    }

The provided schema will generate the following enum:

.. code-block:: php

    enum OfferState: int {
        case Open = 0;
        case Sold = 1;
        case Cancelled = 2;
    }

If an enum which requires a mapping is found but no mapping is provided a **SchemaException** will be thrown.

.. note::

    By enabling the *$skipNonMappedEnums* option of the **EnumPostProcessor** you can skip enums which require a mapping but don't provide a mapping. Those enums will provide the default `enum <../complexTypes/enum.html>`__ behaviour.
