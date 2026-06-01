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
    public string $message = 'This destination domain is not allowed on this instance.';
}
