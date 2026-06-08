<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that a URL's host is not on the instance denylist.
 * Pairs with {@see App\Service\DestinationDenylist}.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AllowedDestination extends Constraint
{
    // Translation key resolved against the `validators` domain in the request
    // locale (CLAUDE.md i18n); see translations/validators.*.yaml.
    public string $message = 'destination.not_allowed';
}
