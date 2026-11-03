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
 * Creates the properties for the references into the definitions of one schema document. A property is shared by all
 * reference sites which point to the same definition and carry identical metadata.
 */
class SchemaDefinition
{
    protected ResolvedDefinitionsCollection $resolvedPaths;
    /** @var array<string, PropertyProxy[]> */
    protected array $unresolvedProxies = [];

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

        if (!$this->resolvedPaths->offsetExists($key)) {
            // create a dummy entry for the path first. If the path is used recursive the recursive usages will point
            // to the currently created property
            $this->resolvedPaths->offsetSet($key, null);

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

                $this->resolvedPaths->offsetSet($key, $property);

                /** @var PropertyProxy $proxy */
                foreach ($this->unresolvedProxies[$key] ?? [] as $proxy) {
                    $proxy->resolve();
                }

                unset($this->unresolvedProxies[$key]);

                return $property;
            } catch (PHPModelGeneratorException $exception) {
                $this->resolvedPaths->offsetUnset($key);

                // The path was navigated above, so everything thrown while the property of the definition is built
                // is a fault in the definition. Restating it as "Unresolved Reference" (RefResolver does so for
                // every unmarked exception) would hide the real cause behind the reference site.
                throw $exception instanceof SchemaException ? $exception->markAsReferencedSchemaFailure() : $exception;
            }
        }

        $proxy = new PropertyProxy($propertyName, $this->source, $this->resolvedPaths, $key);
        $this->unresolvedProxies[$key][] = $proxy;

        if ($this->resolvedPaths->offsetGet($key)) {
            $proxy->resolve();
        }

        return $proxy;
    }
}
