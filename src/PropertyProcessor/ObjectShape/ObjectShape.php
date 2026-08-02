<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

/**
 * Classification of a schema's object shape, deciding how the generator represents and
 * validates its values.
 *
 * - ObjectAsserting  — the schema requires its value to be an object: an explicit
 *                      `type: object` (including a multi-type array whose only listed type is
 *                      "object"), or a composition whose branches jointly guarantee object-ness
 *                      (e.g. an allOf containing an asserting branch). Non-object values fail
 *                      such a schema. Routed through the unconditional object path: a generated
 *                      class is always instantiated, backed by an unconditional instanceof
 *                      check.
 * - ObjectDescribing — the schema constrains object values without asserting object-ness:
 *                      object-targeting keywords (properties, required, ...) present without a
 *                      `type` declaration of any kind (a `type` that merely permits "object"
 *                      among others, e.g. `["object", "string"]`, is NOT describing - it still
 *                      declares a type and is classified NotObject; see below). Per JSON Schema
 *                      semantics such keywords are vacuously satisfied by non-object values, so
 *                      a describing schema accepts any non-object. It is still routed through
 *                      an object path - a generated class IS instantiated for object values,
 *                      exactly like the asserting case - but guarded: a non-object value passes
 *                      through unchanged instead of being instantiated or rejected, which is
 *                      what ObjectModifier's non-asserting mode wires up.
 * - NotObject        — everything else: scalar/array typed schemas, multi-type declarations
 *                      that don't reduce to "object" alone (including ones that permit object
 *                      among other types, e.g. `["object", "null"]`), vacuous schemas, and
 *                      schemas whose object-ness cannot be established conservatively
 *                      (unresolvable or cyclic $refs, filter-bearing schemas).
 */
enum ObjectShape
{
    case NotObject;
    case ObjectDescribing;
    case ObjectAsserting;
}
