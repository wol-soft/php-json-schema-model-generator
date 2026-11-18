<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use PHPModelGenerator\Exception\PHPModelGeneratorException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyProxy;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Hold a definition from a schema
 */
class SchemaDefinition
{
    protected ResolvedDefinitionsCollection $resolvedPaths;
    /** @var array */
    protected $unresolvedProxies = [];
    /**
     * The entry which is currently created for a definition: a reference to the definition found while the entry is
     * created is a recursive reference.
     *
     * @var array<string, string>
     */
    private array $entriesInCreation = [];

    public function __construct(
        protected JsonSchema $source,
        protected SchemaProcessor $schemaProcessor,
        protected Schema $schema,
    ) {
        $this->resolvedPaths = new ResolvedDefinitionsCollection();
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * The raw JsonSchema this definition points at (before any property processing). Used by the
     * object-shape routing decision to statically peek at a `$ref` target without resolving it into
     * a property.
     */
    public function getSource(): JsonSchema
    {
        return $this->source;
    }

    /**
     * Resolve a reference
     *
     * @throws PHPModelGeneratorException
     * @throws SchemaException
     */
    public function resolveReference(
        string $propertyName,
        string $path,
        bool $required,
        ?array $dependencies = null,
        bool $isArrayItem = false,
    ): PropertyInterface {
        $jsonSchema = $this->source->navigate($path);

        // if the properties point to the same definition and share identical metadata the generated property can be
        // recycled. Otherwise, a new property must be generated as diverging metadata lead to different validators.
        // isArrayItem IS included unconditionally, not only when implicit null is allowed. It looks tempting to gate
        // it behind GeneratorConfiguration::isImplicitNullAllowed(), since TypeCheckValidator's own implicit-null
        // guard (allowImplicitNull = isImplicitNullAllowed() && !isRequired()) is already false whenever implicit
        // null is off, independent of isArrayItem. But RenderHelper::isPropertyNullable() computes getter (output)
        // nullability as !isRequired() whenever outputType=true, regardless of isImplicitNullAllowed(). isRequired()
        // is itself `isPropertyRequired || isPropertyArrayItem`, and a PropertyProxy delegates both isRequired() and
        // isArrayItem() to the one shared underlying property. So gating would let an array-item usage and a plain
        // optional-property usage of the same $ref collide onto one shared property when implicit null is disabled,
        // and whichever wired isArrayItem=true would silently flip the other's getter to non-nullable. Confirmed via
        // a $ref used both as `items` of an array and as a plain optional property: gating changed the optional
        // property's generated getter from `?Item` to the incorrect `Item`.
        $key = implode('-', [$path, $required ? '1' : '0', $isArrayItem ? '1' : '0', md5(json_encode($dependencies))]);

        // The name of the property is metadata as well: the validators of a property name it in their exceptions and
        // in checks (the key of the property in the provided data), a property shared by differently named references
        // reports the name of the first reference for all of them (or doesn't validate the later ones at all). Each
        // name therefore gets a property of its own. Only a recursive reference (found while the entry of the
        // definition is created) shares the entry of the property which is created: the recursion has to end in that
        // property, a name specific entry would be created again and again.
        // A definition describing an object gets a property per name as well, its class is still generated once
        // (SchemaProcessor::processSchema() returns the class of the same signature). Sharing the property of an
        // object was rejected: the property is more than an instance check, the keywords which target the property
        // instead of the shared class (const, enum, filter, default) are validated by it, and the const of an optional
        // property checks for the key of the first reference in the provided data.
        $entryKey = $this->entriesInCreation[$key] ?? $key . '-' . md5($propertyName);

        if (!$this->resolvedPaths->offsetExists($entryKey)) {
            // create a dummy entry for the path first. If the path is used recursive the recursive usages will point
            // to the currently created property
            $this->resolvedPaths->offsetSet($entryKey, null);
            $this->entriesInCreation[$key] = $entryKey;

            try {
                $property = (new PropertyFactory())
                    ->create(
                        $this->schemaProcessor,
                        $this->schema,
                        $propertyName,
                        $jsonSchema,
                        $required,
                        $isArrayItem,
                    );

                $this->resolvedPaths->offsetSet($entryKey, $property);
                unset($this->entriesInCreation[$key]);

                /** @var PropertyProxy $proxy */
                foreach ($this->unresolvedProxies[$entryKey] ?? [] as $proxy) {
                    $proxy->resolve();
                }

                unset($this->unresolvedProxies[$entryKey]);

                return $property;
            } catch (PHPModelGeneratorException $exception) {
                $this->resolvedPaths->offsetUnset($entryKey);
                unset($this->entriesInCreation[$key]);

                // The definition was found, creating its property failed. The failure names the real cause and must
                // reach the caller unchanged: the reference resolution restates a failure to locate the definition
                // ("Unresolved Reference") but not a fault in the definition. Restating every failure of a reference
                // was rejected: the message of the real cause (eg. a rejected composition) is hidden behind a message
                // which blames the reference and only available as previous exception.
                throw $exception instanceof SchemaException ? $exception->markAsReferencedSchemaFailure() : $exception;
            }
        }

        $proxy = new PropertyProxy($propertyName, $this->source, $this->resolvedPaths, $entryKey);
        $this->unresolvedProxies[$entryKey][] = $proxy;

        if ($this->resolvedPaths->offsetGet($entryKey)) {
            $proxy->resolve();
        }

        return $proxy;
    }
}
