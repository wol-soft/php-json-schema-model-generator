# Recursive definitions through not/if conditions and schema dependencies

## Phases
- A (done): item filter check of not branch / if condition deferred until recursive references are resolved
- B/C (done): schema dependency referencing the owning definition, pins replaced
- D (done): nested composition + unevaluatedItems tests, recursion + unevaluatedItems tests
- Docs (done): array.rst (recursive compositions, recursive tuple unevaluatedItems), filter.rst, object.rst

## Decisions
- Reading an unavailable PropertyProxy throws UnresolvedPropertyException (an Error). Rejected: LogicException
  (RefResolver catches Exception and restates it as "Unresolved Reference", hiding the programming error).
- ArrayItemFilterScope::assertNoItemFilterInValidationOnlyBranch catches it and re-runs once the proxy resolves.
  Rejected: skipping unresolved proxies (a filter reachable only via the recursion would pass); a separate
  pre-walk for unresolved nodes (duplicates the traversal and silently misses steps it doesn't mirror);
  deferring all checks to the end of processing (changes which exception comes first for non-recursive schemas).
- PropertyProxy::detach() supports an unresolved proxy: the copy takes a clone of the property when the proxy
  resolves. Rejected: plain clone (delegates changes to the shared property).
- transferDependentPropertiesToBaseSchema registers the detached copy at once, strips it on resolve.
  Rejected: deferring the whole addProperty (slot missing from the schema until then, onAllPropertiesResolved
  could fire early).
- Schema::getProperties sort treats an unresolved proxy as without SchemaDependencyValidator.

## Found along the way (not fixed)
- Transferred dependency properties keep their instantiation decorators: object-typed dependency-only properties
  are instantiated/validated even when the property that owns the dependency is absent.
- unevaluatedItems: false inside a composition branch ignores the contains credit of the same branch.
- Collect mode: composition validators run on a value that already failed the type check (noise in messages).
- RefResolver restates any SchemaException inside a local definition as "Unresolved Reference".
