<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\DestinationDenylist;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class AllowedDestinationValidator extends ConstraintValidator
{
    public function __construct(private readonly DestinationDenylist $denylist)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AllowedDestination) {
            throw new UnexpectedTypeException($constraint, AllowedDestination::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($this->denylist->isDenied($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
