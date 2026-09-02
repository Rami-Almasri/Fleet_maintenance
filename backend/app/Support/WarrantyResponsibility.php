<?php

namespace App\Support;

/**
 * WHO gets told, expressed as a capability rather than as a person.
 *
 * The requirement was written in names — "notify Waleed and Abdullah" — and names are exactly what
 * must not end up in this code. A user id in a service is a bug with a delay fuse: it survives the
 * person leaving, it cannot be granted to a second person during a holiday, and it is invisible to
 * every screen that manages access. This codebase already solved that problem once, with Spatie
 * roles and `resource.action` permissions, and the whole of NotificationScanner's fan-out
 * (notifyByPermission / notifyByAnyPermission / notifyByRole) is built on it.
 *
 * So the three responsibilities the warranty flow talks about are DEFINED AS PERMISSIONS, and who
 * holds them is an administrative fact managed on the Users page:
 *
 *   WARRANTY RESPONSIBLE     `warranty.review` — decides whether something is covered, chases the
 *                            dealer, runs the claim. This is the person the whole feature exists to
 *                            put in front of a decision before money moves.
 *
 *   PROCUREMENT RESPONSIBLE  `parts.purchase` — the people who actually buy things. In this fleet
 *                            today that is Waleed and Abdullah, and they hold it because of the
 *                            role they were given, not because their ids appear below.
 *
 *   MAINTENANCE RESPONSIBLE  `maintenance.manage` — the workshop side, told when a car's repair is
 *                            going to take the dealer route instead of the garage route.
 *
 * Read `warrantyResponsible()` as "whoever holds the warranty desk today". If that turns out to be
 * one person, it is one person; if the desk is shared, it is shared; and the answer changes by
 * granting a permission, never by a deploy.
 */
final class WarrantyResponsibility
{
    // ── The permissions themselves ─────────────────────────────────────────────────────────────
    // Kept in lock-step with RolesAndPermissionsSeeder::PERMISSIONS and the route middleware.

    /** See warranties, cases and the warranty dashboard. */
    public const VIEW = 'warranty.view';

    /** Record and edit warranty data: the cover, the window, the provider, the paperwork. */
    public const MANAGE = 'warranty.manage';

    /** Decide a coverage review — covered or not covered. The money decision. */
    public const REVIEW = 'warranty.review';

    /** Open and advance a warranty case: authorisation, dealer, repair, claim. */
    public const CLAIM = 'warranty.claim';

    /**
     * Proceed with a normal purchase in spite of a blocking verdict. Deliberately its own
     * permission and not folded into MANAGE: recording a warranty and overruling one are different
     * levels of trust, and the second is the one that spends money.
     */
    public const OVERRIDE = 'warranty.override';

    /** Close a case and record what was recovered or avoided. */
    public const CLOSE = 'warranty.close';

    public const ALL = [self::VIEW, self::MANAGE, self::REVIEW, self::CLAIM, self::OVERRIDE, self::CLOSE];

    // ── The three responsibilities, as audiences ───────────────────────────────────────────────

    /** The warranty desk: coverage reviews, cases, claims, expiry. */
    public static function warrantyResponsible(): array
    {
        return [self::REVIEW];
    }

    /** The buyers. Told when a verdict releases them to purchase normally, and when one stops them. */
    public static function procurementResponsible(): array
    {
        return ['parts.purchase'];
    }

    /** The workshop. Told when a repair is going down the dealer route rather than the garage one. */
    public static function maintenanceResponsible(): array
    {
        return ['maintenance.manage'];
    }

    /**
     * Warranty desk + workshop, for the events that change what BOTH of them do next — a case being
     * opened, an authorisation landing, a claim being refused. Passed to notifyByAnyPermission, which
     * notifies each person once however many of these they hold, so a manager holding both does not
     * get the same card twice.
     */
    public static function warrantyAndMaintenance(): array
    {
        return array_merge(self::warrantyResponsible(), self::maintenanceResponsible());
    }
}
