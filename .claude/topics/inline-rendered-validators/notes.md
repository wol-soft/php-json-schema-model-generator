NOTES (working memory; planning files under .claude could not be written by the Write tool in this environment)

A. Probe baseline:
 - additionalItems, unevaluatedItems(2019+), contains: STACK OVERFLOW at generation
 - additionalProperties/patternProperties/unevaluatedProperties (typed obj, untyped obj applicator, via array): OK (class)
 - propertyNames: OK ; dependencies/not/if/anyOf: compositions extracted, schema dependency instantiates class
 - not:{$ref self}/dependencies:{a:{$ref self}} on SAME value: TypeError PropertyProxy::getProperty null (self-referencing composition; step 3 scope)
Found: ArrayContains.phptpl and PropertyNames.phptpl closures lack $modelData capture -> warning with any extracted validator inside
Found: additionalItems lacks is_array guard (typed missing+implicitNull, explicit null, untyped null / string)
DESIGN: element-level extraction (E) for additionalItems/unevaluatedItems/contains
  rejected: whole-validator ExtractedMethodValidator for contains ($containsMatches shared with min/maxContains validators,
  $branchContainsMatches shared with composition body); render-time cycle detection (global state, D unsolved)
