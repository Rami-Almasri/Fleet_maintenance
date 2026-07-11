<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An illegal Fleet Maintenance Workflow move: thrown when a ticket is asked to jump to a state
 * its current state does not allow, or when a required handoff field is missing (e.g. dispatching
 * without an odometer reading or a chosen garage). The state machine is enforced server-side so
 * neither a buggy client nor a stale tab can drive a ticket out of sequence. The API maps this to
 * a 422 with the offending from→to so the UI can explain it.
 */
class WorkflowTransitionException extends RuntimeException
{
    /**
     * @param array<string,mixed> $context from/to states + any missing fields, for the API payload
     */
    public function __construct(string $message, public array $context = [])
    {
        parent::__construct($message);
    }
}
