BuilderClassPostProcessor
=========================

.. code-block:: php

    $generator = new ModelGenerator();
    $generator->addPostProcessor(new BuilderClassPostProcessor());

The **BuilderClassPostProcessor** generates a builder class for each model class generated from your schemas.
The generated builder classes can be used to populate the object gradually (e.g. building a response object).
Additionally, a validate method is added to the builder classes which converts the builder class into the corresponding model class and performs a full validation.
The builder class always shares the namespace with it's corresponding model class.

.. hint::

    If your model contains `readonly <../../generic/readonly.html>`__ properties or `immutability <../../gettingStarted.html#immutable-classes>`__ is enabled, the builder class of course will generate setter methods independent of those settings.

Let's have a look at a simple object and the generated classes:

.. code-block:: json

    {
        "$id": "example",
        "type": "object",
        "properties": {
            "example": {
                "type": "string"
            }
        },
        "required": [
            "example"
        ]
    }

In this case the model generator will generate two classes: **Example** and **ExampleBuilder**. Generated interfaces:

.. code-block:: php

    // class ExampleBuilder
    public function setExample(string $members): static;
    public function getExample(): ?string;

    public function validate(): Example;

    // class Example
    public function setExample(string $members): static;
    public function getExample(): string;

Note, that the *getExample* method of the **ExampleBuilder** can return null.
This applies for all getter methods of the builder instance, as we don't know if the property has already been set on the object.
In contrast to the general model class, which is fully populated via an array provided in the constructor, the constructor of the builder class accepts any subset of the properties.
The remaining properties can be populated via the generated setter methods.
Neither the constructor nor the setter methods perform further validation, so if your property has for example a **minLength** constraint and the value you set doesn't fulfill the constraint, the set will not fail.
The call to validate will populate a new instance of **Example** and perform a full validation of all constraints and, in case of violations, throw an exception matching your `error handling configuration <../../gettingStarted.html#collect-errors-vs-early-return>`__.

If we want to implement the builder as a builder for responses, a full code example might look like the following (assuming `serialization <../../gettingStarted.html#serialization-methods>`__ is enabled):

.. code-block:: php

    $builder = new ExampleBuilder();
    $builder->setExample('123abc');

    // this call will throw an exception on violations against the JSON schema
    $responseBody = $builder->validate();
    $response = new Response($responseBody->toJSON());

Nested objects
~~~~~~~~~~~~~~

When your schema provides nested objects, you have different options to populate the nested object in the builder class.
As the **BuilderClassPostProcessor** generates a builder class for each generated model class, you can of course simply use the builder class to populate the nested object.
In this case, you don't need to perform the validation yourself but leave it for the main call to the parents *validate* method.
Nevertheless, you can validate the object and pass a fully validated instance of the nested object (or, if you haven't used the builder but instantiated the object in a different way, that's also perfectly fine).
As a third option, you can simply pass an array with the values for the nested object.

.. code-block:: json

    {
        "$id": "location",
        "type": "object",
        "properties": {
            "coordinates": {
                "$id": "coordinates",
                "type": "object",
                "properties": {
                    "latitude": {
                        "type": "string"
                    },
                    "longitude": {
                        "type": "string"
                    },
                },
                "required": [
                    "latitude",
                    "longitude"
                ]
            }
        }
    }

In this case the model generator will generate four classes with the following interfaces:

.. code-block:: php

    // class CoordinatesBuilder
    public function setLatitude(string $latitude): static;
    public function setLongitude(string $longitude): static;
    public function getLatitude(): ?string;
    public function getLongitude(): ?string;

    public function validate(): Coordinates;

    // class Coordinates
    public function setLatitude(string $latitude): static;
    public function setLongitude(string $longitude): static;
    public function getLatitude(): string;
    public function getLongitude(): string;

    // class LocationBuilder

    // $coordinates accepts an instance of Coordinates, CoordinatesBuilder or an array.
    // If an array is passed, the keys 'latitude' and 'longitude' must be present for a successful validation
    public function setCoordinates($coordinates): static;
    // returns, whatever you passed to setCoordinates, or null, if you haven't called setCoordinates yet
    public function getCoordinates();

    public function validate(): Location;

    // class Location
    public function setCoordinates(Coordinates $coordinates): static;
    public function getCoordinates(): ?Coordinates;

Let's have a look at the usage of the generated classes with the different approaches of populating the **Coordinates** on the **LocationBuilder**:

.. code-block:: php

    $latitude = '53°7\'6"N';
    $longitude = '7°27\'43"E';
    $locationBuilder = new LocationBuilder();

    // option 1: passing an array with the data
    $locationBuilder->setCoordinates(['latitude' => $latitude, 'longitude' => $longitude]);

    // option 2: passing an instance of the CoordinatesBuilder
    $coordinatesBuilder = new CoordinatesBuilder();
    $coordinatesBuilder->setLatitude($latitude);
    $coordinatesBuilder->setLongitude($longitude);
    $locationBuilder->setCoordinates($coordinatesBuilder);

    // option 3: passing an instance of Coordinates,
    // either by manually validating the builder or by instantiating it directly.
    // Both options might throw exceptions if the data is not valid for the Coordinates class
    $locationBuilder->setCoordinates($coordinatesBuilder->validate());
    $locationBuilder->setCoordinates(new Coordinates(['latitude' => $latitude, 'longitude' => $longitude]));

The same behaviour applies, if the property of the parent object holds an array of nested objects.
In this case, each element of the nested array might use any of the possible options.
The call to the *validate* method on the parent object will cause all elements to be instantiated with the corresponding model class.
