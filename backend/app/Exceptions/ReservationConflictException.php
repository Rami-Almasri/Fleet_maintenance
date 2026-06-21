<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an action (e.g. sending a car to maintenance) clashes with a paid
 * reservation. Carries the conflicting reservation(s) so the API can explain them
 * and offer an explicit override.
 */
class ReservationConflictException extends RuntimeException
{
    /**
     * @param array<int,array<string,mixed>> $reservations
     */
    public function __construct(string $message, public array $reservations = [])
    {
        parent::__construct($message);
    }
}
