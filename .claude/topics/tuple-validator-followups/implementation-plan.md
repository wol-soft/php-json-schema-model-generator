# Tuple / array validator follow-ups (working notes, never committed)

Findings: A boolean schema in tuple items, B shared definition reports first property's name,
C complete message assertions in TupleArrayPropertyTest, D tuple example in docs/source/complexTypes/array.rst.

## B audit (one definition referenced by propA and propB, error of propB names propA)
Red before the fix: items (outer + inner names), allOf/anyOf/oneOf/not/if-then-else (outer + branch names),
const (propB silently accepts invalid values: key presence check bakes the name), filter (message names propA),
tuple inner item names. Green: enum, format, pattern, min/max*, multipleOf, type, multi-type, contains, min/maxContains,
min/maxItems, uniqueItems, additionalItems, unevaluatedItems, object definitions (nested class).

## Phases
1. B: reproduce -> fix -> tests per kind
2. A: boolean schema in tuple items
3. C: tuple tests with complete messages
4. D: docs
5. Docs audit (docs/source) for all of the above
