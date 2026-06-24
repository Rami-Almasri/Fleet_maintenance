<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * "Rental-First" policy block: thrown when someone tries to open a maintenance (type-U)
 * contract on a car that still has a live rental. Staff are hard-blocked and should instead
 * log a workshop event against the active rental. Carries the rental so the API can explain
 * it and offer a manager-only override. See OperationsService::startOperation.
 */
class RentalActiveException extends RuntimeException
{
    /**
     * @param array<string,mixed> $rental snapshot of the conflicting open rental
     */
    public function __construct(string $message, public array $rental = [])
    {
        parent::__construct($message);
    }
}
