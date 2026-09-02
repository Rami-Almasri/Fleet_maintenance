<?php

namespace App\Support;

/**
 * The canonical expense types — FleetView's own vocabulary for "what kind of cost is this".
 *
 * These are OURS, not Odoo's. An expense type says what the business did (a car was repaired, a car was
 * washed, a driver took a taxi); it says nothing about which ledger account it lands in or which document
 * records it. Those two are separate questions and each is answered by configuration:
 *
 *     ExpenseType  →  Odoo expense account   (WHAT it is charged to)
 *     ExpenseType  →  Odoo document type     (HOW it is recorded: vendor bill / expense)
 *
 * Both live on {@see \App\Models\ExpenseTypeMapping}, one row per type, editable by Finance. That
 * separation is deliberate and load-bearing: REPAIR is not "a vendor bill", it is a repair that TODAY
 * happens to be recorded as a vendor bill. When Finance decides a category should be recorded
 * differently they change a row, not this file, and no code moves.
 *
 * The codes are stable and stored — they are what `financial_events.expense_type` holds — so they must
 * never be renamed. Labels are display and may change freely.
 *
 * WHERE EACH ONE COMES FROM TODAY. Only some of these have an operational workflow in FleetView that
 * can produce them; the rest are configured and validated but have no producer yet (see
 * {@see \App\Services\Odoo\FinancialEventBuilder}). Documented here so nobody mistakes "no events of
 * this type" for a bug:
 *
 * WHERE EACH ONE COMES FROM. Every type has an operational producer, and each one is a record of the
 * WORK, not a finance form — the cost attaches to the thing that caused it:
 *
 *   REPAIR       {@see \App\Models\MaintenanceInvoice} on a breakdown ticket
 *   ROUTINE      {@see \App\Models\MaintenanceInvoice} on a routine/scheduled-service ticket
 *   RECOVERY     {@see \App\Models\Maintenance} — the tow leg (recovery_* columns)
 *   FUEL         {@see \App\Models\FuelFill} — litres + odometer, cost is one of its facts
 *   REGISTRATION {@see \App\Models\VehicleRegistration} — the renewal_* columns on the record renewed
 *   CAR_WASH     {@see \App\Models\VehicleWashJob} — an EXTERNAL wash; an internal one raises nothing
 *   TAXI         {@see \App\Models\LogisticsTask} — the driver's fare on the movement that caused it
 */
final class ExpenseType
{
    public const REPAIR       = 'REPAIR';
    public const ROUTINE      = 'ROUTINE';
    public const RECOVERY     = 'RECOVERY';
    public const FUEL         = 'FUEL';
    public const REGISTRATION = 'REGISTRATION';
    public const CAR_WASH     = 'CAR_WASH';
    public const TAXI         = 'TAXI';

    public const ALL = [
        self::REPAIR,
        self::ROUTINE,
        self::RECOVERY,
        self::FUEL,
        self::REGISTRATION,
        self::CAR_WASH,
        self::TAXI,
    ];

    public const LABELS = [
        self::REPAIR       => 'Repair maintenance',
        self::ROUTINE      => 'Routine maintenance',
        self::RECOVERY     => 'Recovery & towing',
        self::FUEL         => 'Fuel for maintenance',
        self::REGISTRATION => 'Registration & licensing',
        self::CAR_WASH     => 'Car washing',
        self::TAXI         => 'Public transportation — taxi',
    ];

    /**
     * The types that are ALWAYS about one specific vehicle, and therefore cannot be sent to Odoo without
     * that vehicle's analytic account. Taxi is the exception: a driver's fare is a fleet-admin cost that
     * often belongs to no single car, so requiring an analytic account on it would block a legitimate
     * expense forever. See {@see \App\Services\Odoo\FinancialValidator}.
     */
    public const VEHICLE_BOUND = [
        self::REPAIR,
        self::ROUTINE,
        self::RECOVERY,
        self::FUEL,
        self::REGISTRATION,
        self::CAR_WASH,
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? 'Unknown';
    }

    public static function isValid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** Does a cost of this type have to name the car it was spent on? */
    public static function requiresVehicle(?string $type): bool
    {
        return in_array($type, self::VEHICLE_BOUND, true);
    }
}
