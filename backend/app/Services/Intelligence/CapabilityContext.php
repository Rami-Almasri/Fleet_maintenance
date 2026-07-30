<?php

namespace App\Services\Intelligence;

use App\Models\Maintenance;

/**
 * The decision being made right now.
 *
 * Capabilities are handed this and nothing else. It deliberately describes a MOMENT — a ticket at a
 * workflow state, in front of an actor — rather than a screen, because the pipeline binds knowledge
 * to decisions, not to pages. The same capability fires wherever that decision is made.
 */
final readonly class CapabilityContext
{
    /**
     * @param string[] $signatures canonical signatures in play for this decision
     */
    public function __construct(
        public ?Maintenance $ticket = null,
        public ?int $vehicleId = null,
        public array $signatures = [],
        public ?string $workflowState = null,
        public ?int $actorId = null,
        public array $extra = [],
    ) {}

    public static function forTicket(Maintenance $ticket, array $signatures = [], ?int $actorId = null): self
    {
        return new self(
            ticket: $ticket,
            vehicleId: $ticket->vehicle_id,
            signatures: $signatures,
            workflowState: $ticket->workflow_status,
            actorId: $actorId,
        );
    }

    public function ticketId(): ?int
    {
        return $this->ticket?->id;
    }
}
