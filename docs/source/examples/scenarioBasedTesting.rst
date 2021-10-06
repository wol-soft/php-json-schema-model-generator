Scenario-based integration tests
================================

The scenario-based test data generation for integration tests utilizes the schema model generator for validation of the test data and for an easier implementation.

The base idea behind this concept is described best in four simple steps. We'll look into the steps in detail with an example later.

* You define a JSON-Schema file which defines the format of your **scenario** (let's call it **scenario-schema**)
* You set up the model generator to generate classes from your **scenario-schema**
* You write a **ScenarioBuilder** which transfers a **scenario** (eg. inserts required records into the database)
* You write integration test based on test data defined in a **scenario** as a JSON file following your **scenario-schema**

Let's assume an application which we use to describe the concept. We have implemented the `Swagger Petstore <https://petstore.swagger.io/>`_ where we have users, a store with an inventory and pets (description is based on the Petstore v1.0.5).

Setting up our structure
------------------------

Let's first have a look at our directory structure:

| **petstore**
| ├── **src**
| ├── **tests**
| │   ├── **Unit**
| │   └── **Integration**
| │       ├── **Scenario**
| │       │   ├── **Generated**
| │       │   ├── generateScenarioModels.php
| │       │   ├── ScenarioBuilder.php
| │       │   └── ScenarioSchema.json
| │       ├── **Controller**
| │       │   ├── PetControllerTest.php
| │       │   └── PetControllerTest.json
| │       └── ...
| ├── composer.json
| └── ...

Now let's have a closer look at some of the files and their function:

* **ScenarioSchema.json**: This file contains our **scenario-schema** which defines how our test data must be defined
* **generateScenarioModels.php**: This file contains our code which uses the model generator to create a class from the *ScenarioSchema.json*. The class will be used to validate our **scenarios** and will help us to implement our **ScenarioBuilder**.
* **ScenarioBuilder.php**: In this class we implement our code to transfer the provided test data from a **scenario** into our database. The generated code will help us implementing the **ScenarioBuilder**
* **PetControllerTest.json**: This file contains our **scenario** for the PetControllerTest. The data is defined as a JSON following the schema defined in our **scenario-schema**
* **PetControllerTest.php**: Finally our integration test which contains our test cases based on the data defined in our **scenario**

Defining our scenario schema
----------------------------

To define our **scenario-schema** we look at our entities and add them to our schema file (**ScenarioSchema.json**) so we get something like the following schema (contains not all properties as it's just an example):

.. code-block:: json

    {
      "$id": "Scenario",
      "type": "object",
      "additionalProperties": false,
      "description": "This schema describes the structure of a test scenario which can be set up via the ScenarioBuilder",
      "properties": {
        "$schema": {
          "type": "string"
        },
        "users": {
          "type": "array",
          "items": {
            "$id": "user",
            "type": "object",
            "properties": {
              "username": {
                "type": "string"
              },
              "email": {
                "type": "string"
              },
              "userStatus": {
                "type": "integer",
                "default": 0
              }
            },
            "required": [
              "username"
            ]
          }
        },
        "pets": {
          "type": "array",
          "items": {
            "$id": "pet",
            "type": "object",
            "properties": {
              "name": {
                "type": "string"
              },
              "status": {
                "enum": ["available", "pending", "sold"],
                "default": "available"
              }
            },
            "required": [
              "name"
            ]
          }
        },
        "orders": {
          "type": "array",
          "items": {
            "$id": "order",
            "type": "object",
            "properties": {
              "id": {
                "type": "integer"
              }
              "user": {
                "type": "string"
              },
              "pet": {
                "type": "string"
              },
              "status": {
                "enum": ["placed", "approved", "delivered"],
                "default": "delivered"
              }
            },
            "required": [
              "id",
              "user",
              "pet"
            ]
          }
        }
      }
    }

.. hint::

    To avoid adding fallback logics for properties into your **ScenarioBuilder** add default values to your **scenario-schema**.

    To have a proper validation of your **scenario** add validation rules to your **scenario-schema** (eg. required)

The **scenario-schema** already gives a sneak preview of how we will link our entities in the scenarios. Each pet has a unique name and each user has a unique username which we will use to identify our entities. Let's continue to generate some code first before we will have a deeper look into this topic.

Generating code from our scenario-schema
----------------------------------------

As the next step after defining our **scenario-schema** we will generate a PHP class to validate our **scenarios** and to implement our **ScenarioBuilder**.

We'll use the schema model generator to create a Scenario class with the following script (**generateScenarioModels.php**):

.. code-block:: php

    <?php

    declare(strict_types=1);

    use PHPModelGenerator\Model\GeneratorConfiguration;
    use PHPModelGenerator\ModelGenerator;
    use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;

    require_once __DIR__ . '/../../vendor/autoload.php';

    define('GENERATED_DIR', __DIR__ . '/Generated');

    $generator = new ModelGenerator((new GeneratorConfiguration())
        ->setNamespacePrefix('\PetStoreTest\Integration\Scenario\Generated')
    );

    $generator
        ->generateModelDirectory(GENERATED_DIR)
        ->generateModels(new RecursiveDirectoryProvider(__DIR__), GENERATED_DIR);

Now we can add a scripts-section to our composer.json to create a build script which runs our **generateScenarioModels.php**:

.. code-block:: json

    ...
    "scripts": {
        "build": "php tests/Server/Scenario/generateScenarioModels.php"
    },
    ...

By running **composer run build** we will generate our Scenario class. Don't forget to re-build when modifying your **scenario-schema**.

Implementing the ScenarioBuilder
--------------------------------

Now as we have generated our Scenario class we will use it to transform a **scenario** into persisted data. Basic steps to implement the builder are:

* Implement a constructor which accepts a path to a JSON file containing the **scenario**. The constructor then uses the generated Scenario class to parse and validate the **scenario**.
* Add methods which persist the entities from the **scenario**
* Add methods to access the persisted entities

An implementation example of a **ScenarioBuilder** can look like the following class (partially simplified):

.. code-block:: php

    <?php

    use ...

    class ScenarioBuilder
    {
        // we keep links to all generated entities so we can access the entities
        // later from the test cases eg. to perform assertions

        /** @var Pet[] */
        private array $pets = [];
        /** @var User[] */
        private array $users = [];
        /** @var Order[] */
        private array $orders = [];

        public function __construct(string $scenarioFile)
        {
            $scenarioData = json_decode(file_get_contents($scenarioFile), true);

            if (!$scenarioData) {
                throw new Exception("Failed to load scenario schema $scenarioFile");
            }

            try {
                $scenario = new Scenario($scenarioData);
            } catch (ErrorRegistryExceptionInterface $validationException) {
                throw new Exception("Invalid Scenario provided in $scenarioFile", 0, $validationException);
            }

            // make sure we start our test on a clean DB to avoid side effects between multiple scenarios.
            $this->cleanUpDB();

            $this->setupUser($scenario->getUsers() ?? []);
            $this->setupPets($scenario->getPets() ?? []);
            $this->setupOrders($scenario->getOrders() ?? []);
        }

        /**
         * By using type annotations from the generated classes we have auto completion available
         * to implement our builder logic.
         *
         * @param Scenario_User[] $users
         */
        private function setupUser(array $users): void
        {
            foreach ($users as $user) {
                $this->users[$user->getUsername()] = new User(
                    $user->getUsername(),
                    // as the email field is optional without a default value in our scenario-schema
                    // we implement a fallback logic in this place
                    $user->getEmail() ?? "{$user->getUsername()}@example.com",
                    $user->getUserStatus(),
                );

                if ($this->users[$user->getUsername()]->persist() !== true) {
                    throw new Exception("Failed to persist user {$user->getUsername()}");
                }
            }
        }

        // setupPets works exactly like setupUser, so we skip it in this example

        /**
         * @param Scenario_Order[] $orders
         */
        private function setupOrders(array $orders): void
        {
            foreach ($orders as $order) {
                $this->orders[$order->getId()] = new Order(
                    $order->getId(),
                    // now we use our internal methods to fetch the IDs of linked entities
                    $this->getUser($order->getUser())->getId(),
                    $this->getPet($order->getPet())->getId(),
                    $order->getStatus(),
                );

                if ($this->orders[$order->getId()]->persist() !== true) {
                    throw new Exception("Failed to persist order {$order->getId()}");
                }
            }
        }

        /**
         * This functions are public as we want to access the entities from our test cases
         */
        public function getUser(string $username): User
        {
            return $this->users[$username] ?? throw new Exception("User $username does not exist in scenario");
        }

        // the methods to fetch a pet or a order from the scenario work exactly like getUser,
        // so we skip them in this example

Now we have a class which can transfer a **scenario** into our database.

Writing our first scenario
--------------------------

To start using our **ScenarioBuilder** we now write our first **scenario**. As an example we will write a **scenario** for our PetControllerTest. Our **scenario** will be located right next to the test in the file **PetControllerTest.json**.


.. code-block:: json

    {
      "$schema": "../Scenario/ScenarioSchema.json",
      "users": [
        {
          "username": "Bob"
        }
      ],
      "pets": [
        {
          "name": "doggie"
        }
      ],
      "orders": [
        {
          "id": 12345,
          "user": "Bob",
          "pet": "doggie"
        }
      ],
    }

.. hint::

    As we can see we link our entities with their names instead of using IDs. Why do we do this? Because it's easier to work with names. In your test case you now look at your scenario and you see: alright, user Bob placed one order. If you want to work with IDs only you may go for it. Just change your **scenario-schema** to contain IDs for each entity and change your **ScenarioBuilder** to persist the entities with the provided IDs.

Writing our test cases
----------------------

Finally we've written our first scenario with a few entities. Now let's write a test case using the scenario to set up the data.

.. code-block:: php

    <?php

    use ...

    class PetControllerTest extends ControllerTest
    {
        // we store our ScenarioBuilder instance to access our persisted entities
        protected static ScenarioBuilder $scenario;

        // you may want to put this logic into an abstract class and only define the scenario file
        // in each test class (eg. overwrite a constant, implement logic to map automatically from
        // the class name, ...)
        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            self::$scenario = new ScenarioBuilder(__DIR__ . '/PetControllerTest.json');
        }

        // our first test case - finally!
        public function testGetPet(): void
        {
            // let's first fetch our persisted pet from the scenario
            $pet = self::$scenario->getPet('doggie');

            // execute the API request
            $response = $this->request('GET', "/pet/{$pet->getId()}");

            // execute assertions
            $this->assertSame('doggie', $response->getBody()['name']);
        }
    }

As we can see in the example it's very easy now to implement the test cases as the test cases don't need to care about the data any longer. Also the set up of our test class is short as we just call the **ScenarioBuilder** to set up the data.

But this was a lot of work to just check of we can fetch a pet from the API?

Yes, it was. But keep in mind: your entities are likely bigger, you may have many entities which are linked to each other and you need a lot of test data sometimes. You can now easily set up new/complex scenarios (in an IDE also with auto completion as the scenarios refer to our scenario-schema) and test against them.

In a larger context you may want to structure your **scenario-schema** more user-orientated instead of representing the entities of your application one-to-one. Let's assume you extend your Petshop with subscriptions so a user can subscribe to get updates on various pets (eg. changes of the availability). Now you can go one way and add an entity *petSubscription* to the **scenario-schema** which links a pet to a user with the properties *user* and *pet* (just like a subscription entity in your code). But as we want simple scenarios we could also change the *pet* entity and add a list of subscribers to the entity in our **scenario-schema**:

.. code-block:: json

    "pets": {
      "type": "array",
      "items": {
        ...,
        "properties": {
          ...,
          "subscribers": {
            "type": "array",
            "items": {
              "type": "string"
            }
          }
        }
      }
    },

In our **ScenarioBuilder** we extend the setupPets method to also persist our subscriptions. Now our **scenario** in a SubscriberTest can look like:

.. code-block:: json

    ...,
    "pets": [
      {
        "name": "doggie",
        "subscribers": [
          "Bob",
          "Alice"
        ]
      }
    ],
    ...

You can even extend the concept. As an example for using the **ScenarioBuilder** not only in your integration tests: if you have multiple services working together, each service can implement a **ScenarioBuilder** for their data and provide a test API endpoint which accepts a **scenario** and uses the **ScenarioBuilder** to set up the test data. If you want to write E2E tests covering multiple services you can set up a **scenario** for each service and send the **scenario** to the corresponding API endpoint. After all services have set up their data you can start your tests.
