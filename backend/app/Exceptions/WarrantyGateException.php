<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The purchase was stopped because somebody else might be paying for it.
 *
 * Its own exception rather than a ValidationException carrying a field error, because this is not a
 * validation failure and treating it as one would render it as a red line under an input box. What
 * happened is that a business rule declined an action and produced a DECISION for the user to take:
 * review the warranty, wait for the desk, or override with a reason. The UI needs the whole
 * assessment — which warranties are live, who the provider is, what their number is, whether this
 * user is even allowed to override — to draw that card, and none of that fits in a message string.
 *
 * `context` is the CoverageAssessment payload plus the vehicle and the caller's own permissions,
 * shaped in WarrantyProcurementGuard. ResponseHelper::fromException turns it into the standard 422
 * envelope with `data` populated, which is exactly the shape the part-request form already reads for
 * WorkflowTransitionException — so the frontend gains a card, not a new error protocol.
 */
class WarrantyGateException extends RuntimeException
{
    /**
     * @param array<string,mixed> $context the coverage assessment, the car, and what the user may do
     */
    public function __construct(string $message, public array $context = [])
    {
        parent::__construct($message);
    }
}
