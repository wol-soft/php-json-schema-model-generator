> SUPERSEDED STATUS NOTE: the phases below cover the first part of the work only (serialization of filtered items of nested arrays). The overall state of the pull request is in `.claude/issues/213/handoff.md`.

# Implementation plan

Status: ALL PHASES DONE. A: 6b187a0, B: 1927c4c + c185059 (dead branches removed after Coveralls), C: 88fb365 (docs).
Full suite green in full-draft mode (9490 tests, 0 failures); PR CI green on c185059, Coveralls +0.004%.
Issues created: nested unevaluatedItems (#213), recursive tuple generator overflow (#214, reproduction as test code
in the issue, nothing pinned in the suite on purpose).
Environment note: the code quality tests shell out to vendor/bin/phpcs with the default `php` (8.3 here, composer
requires 8.4) -> run the suite with `PATH=/opt/php84/bin:$PATH`, otherwise 11 GeneratedCodeFormattingTest entries fail.

Phase A result: `tests/Filter/FilterNestedArrayItemsTest.php` (58 test entries over 3 drafts, 47 red, 11 green by design:
recursion regression x3, invariant rejection x6, pinned crash of nested unevaluatedItems x2) + 18 fixtures in
`tests/Schema/FilterNestedArrayItemsTest/` + `ArrayPropertyTest::testFilteredItemsOfNestedArraysAreSerialized`
(converted from the pinned known-gap test, red). All 47 red entries fail with `DateTime` serialized as `[]`
(verified: assertion failures only, no errors). PHPCS clean. Not staged yet (stage after the final diff review).
Recursive tuple generator overflow (analysis.md bug A) deliberately NOT pinned in the suite: a stack overflow inside
the PHPUnit process is only safe when PHP's stack guard is active; reproducer kept in `repro/RecursiveTupleArray.json`.
Policy: never start the next phase without the user's go-ahead; tests first, confirm red, then fix, confirm green.

## Phase A - reproduction tests (red)
New class `tests/Filter/FilterNestedArrayItemsTest.php` (fixtures `tests/Schema/FilterNestedArrayItemsTest/`),
grouped by (schema, configuration):
- two levels: update the pinned `ArrayPropertyTest::testKnownGapFilteredItemsOfNestedArraysAreNotSerialized`
  to the correct output (rename without "KnownGap")
- three levels; outer tuple index holding an array; inner tuple-form items; inner allOf / anyOf / oneOf / then-else
  branch; outer branch declaring the nested items; items = anyOf(array, integer); nullable inner array
- constructor / setter / already transformed input / empty inner arrays / immutable / toJSON / $except
- holders: additionalProperties (one + two levels), patternProperties, unevaluatedProperties (2019-09+, accessor post processor)
- regression: recursive schema-form array still generates and serializes
- invariant: transforming filter on an array item / array additionalProperties value is rejected (SchemaException)
- pinned known gaps: nested unevaluatedItems crash (issue #213), recursive tuple generator overflow (bug A)

## Phase B - implementation
- model of the slots of one array level (leaf / nested) built recursively from `ArrayItemFilterScope::declarationsOf`
  with cycle guard and path-conditional propagation
- one generated method per level, template extended; root keeps the dynamic slot
- holder serializers (AdditionalProperties / PatternProperty / UnevaluatedProperties templates) call the level method

## Phase C - documentation
- filter.rst: remove the `.. warning::` and document nested arrays + holders; check the intro sentence of
  "Filters on array items"
- array.rst (line ~159), allOf/anyOf/oneOf/if/not.rst notes, additionalProperties / patternProperties /
  unevaluatedProperties rst pages (audit), README.md (audit)
- document the nested `unevaluatedItems` limitation textually (no issue number in docs)

## Phase D - verification
- remove superseded pinned test; phpcs on changed files; full suite on PHP 8.4
  (`/opt/php84/bin/php -d memory_limit=256M ./vendor/bin/phpunit --no-coverage --display-warnings`)
- grep the diff for `Phase `, `decision `, `§`, `#<number>`; stage (never `.claude/`)
