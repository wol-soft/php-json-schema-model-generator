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
 * Creates the properties for the references into the definitions of one schema document. A property is shared by the
 * reference sites which point to the same definition with identical metadata and name, and by the recursive references
 * found while it is created.
 */
class SchemaDefinition
{
    protected ResolvedDefinitionsCollection $resolvedPaths;
    /** @var array<string, PropertyProxy[]> */
    protected array $unresolvedProxies = [];
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
     * Resolve a reference.
     *
     * A failure to reach the definition (the path doesn't resolve) is thrown as it is. A fault in the definition
     * itself is marked as a referenced schema failure: the reference resolved, so the caller must not restate the
     * fault as an unresolved reference.
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
        bool $objectAsserted = false,
    ): PropertyInterface {
        $jsonSchema = $this->source->navigate($path)->withObjectAssertedByContext($objectAsserted);

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
        // $objectAsserted is part of the key as well: a definition which describes an object without declaring the type
        // is judged by the site which references it (see JsonSchema::isObjectAssertedByContext()), so a site which
        // asserts an object and one which doesn't can't share the property.
        $key = implode('-', [
            $path,
            $required ? '1' : '0',
            $isArrayItem ? '1' : '0',
            md5(json_encode($dependencies)),
            $objectAsserted ? '1' : '0',
        ]);

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

                // The path was navigated above, so everything thrown while the property of the definition is built
                // is a fault in the definition. Restating it as "Unresolved Reference" (RefResolver does so for
                // every unmarked exception) would hide the real cause behind the reference site: the message of the
                // real cause (eg. a rejected composition) would only be available as previous exception.
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
