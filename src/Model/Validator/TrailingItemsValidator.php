<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * The Draft 2020-12 spelling of {@see AdditionalItemsValidator}: once `prefixItems` carries the
 * tuple, `items` no longer means "every entry" but "every entry the tuple did not cover", which is
 * exactly the role `additionalItems` plays alongside a tuple-form `items` in earlier drafts.
 *
 * Only the two keyword names differ, so the behaviour, message wording and exception type are
 * inherited unchanged — a trailing entry is reported as an additional item either way.
 */
class TrailingItemsValidator extends AdditionalItemsValidator
{
    protected const PROPERTIES_KEY = 'prefixItems';
    protected const ADDITIONAL_PROPERTIES_KEY = 'items';
}
