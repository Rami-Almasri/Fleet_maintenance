<?php

namespace App\Intelligence\Evidence;

use App\Intelligence\Evidence\Queries\GarageDomainRecurrenceQuery;
use App\Intelligence\Evidence\Queries\GarageRecurrenceQuery;
use App\Intelligence\Evidence\Queries\GarageServiceQuery;
use App\Intelligence\Recurrence\RecurrenceRepository;
use InvalidArgumentException;

/**
 * Resolves an evidence id to the query that can prove the claim.
 *
 * ── THE ID FORMAT IS PART OF THE CONTRACT ────────────────────────────────────────────────────────
 *     recurrence.garage:331
 *     recurrence.garage_domain:331:brakes
 *
 * A metric emits its own evidence id (see GarageScorecardService), so a card carries the route to
 * its own proof. The alternative — the frontend assembling ids from whatever fields it happens to
 * have — puts knowledge of the evidence layer in the UI, and the first metric whose evidence needs
 * a different shape breaks it silently.
 *
 * Unknown ids throw rather than returning an empty drawer. "No evidence found" and "I do not know
 * what you are asking about" look identical to a user and mean very different things to us.
 */
class EvidenceRegistry
{
    public function __construct(private RecurrenceRepository $recurrence)
    {
    }

    public function resolve(string $id): EvidenceQuery
    {
        [$kind, $args] = $this->parse($id);

        return match ($kind) {
            'recurrence.garage' => new GarageRecurrenceQuery(
                $this->recurrence,
                (int) ($args[0] ?? 0),
            ),
            // The Services tab of the same drawer: what base() scopes out of every rate.
            'recurrence.garage_services' => new GarageServiceQuery(
                $this->recurrence,
                (int) ($args[0] ?? 0),
            ),
            'recurrence.garage_domain' => new GarageDomainRecurrenceQuery(
                $this->recurrence,
                (int) ($args[0] ?? 0),
                (string) ($args[1] ?? ''),
            ),
            default => throw new InvalidArgumentException("Unknown evidence id [{$id}]."),
        };
    }

    public function knows(string $id): bool
    {
        try {
            $this->resolve($id);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function parse(string $id): array
    {
        $parts = explode(':', $id);
        $kind  = array_shift($parts) ?? '';

        return [$kind, $parts];
    }
}
