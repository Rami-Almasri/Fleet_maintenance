<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a vehicle cannot start an operation (e.g. expired registration/insurance
 * blocks renting).
 */
class VehicleRestrictedException extends RuntimeException
{
}
