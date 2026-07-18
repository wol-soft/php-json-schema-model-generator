<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

/**
 * Classification of a schema's object shape, deciding how the generator represents and
 * validates its values.
 *
 * - ObjectAsserting  — the schema requires its value to be an object: an explicit
 *                      `type: object`, or a composition whose branches jointly guarantee
 *                      object-ness (e.g. an allOf containing an asserting branch). Non-object
 *                      values fail such a schema. Only asserting schemas are eligible for
 *                      routing through the object path (generated class + instantiation +
 *                      instanceof validation).
 * - ObjectDescribing — the schema constrains object values without asserting object-ness:
 *                      object-targeting keywords (properties, required, ...) without a `type`
 *                      declaration. Per JSON Schema semantics such keywords are vacuously
 *                      satisfied by non-object values, so a describing schema accepts any
 *                      non-object. It must never be object-typed or routed through the object
 *                      path; its object constraints only apply guarded to object values.
 * - NotObject        — everything else: scalar/array typed schemas, multi-type declarations,
 *                      vacuous schemas, and schemas whose object-ness cannot be established
 *                      conservatively (unresolvable or cyclic $refs, filter-bearing schemas).
 */
enum ObjectShape
{
    case NotObject;
    case ObjectDescribing;
    case ObjectAsserting;
}
