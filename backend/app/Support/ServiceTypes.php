<?php

namespace App\Support;

/**
 * The canonical vocabulary of performed-action types — the ONE list ServiceRecord validates
 * against, kept deliberately aligned with the ServiceReminder types (oil/battery/filters/tyres)
 * and the findings-catalog categories so "last done per type" and reminder roll-forward speak
 * the same language.
 *
 * Add here first; consumers read this class, never their own copies.
 */
final class ServiceTypes
{
    public const OIL_CHANGE     = 'oil_change';
    public const BATTERY_CHECK  = 'battery_check';
    public const FILTER_CHANGE  = 'filter_change';
    public const TYRE_SERVICE   = 'tyre_service';    // fitting/balancing on existing tyres
    public const TYRE_ROTATION  = 'tyre_rotation';
    public const ALIGNMENT      = 'alignment';
    public const BRAKE_SERVICE  = 'brake_service';
    public const AC_SERVICE     = 'ac_service';
    public const INSPECTION     = 'inspection';
    public const DIAGNOSTICS    = 'diagnostics';
    public const CLEANING       = 'cleaning';
    public const PROGRAMMING    = 'programming';
    public const REPAIR_LABOR   = 'repair_labor';    // generic labor against a fault
    public const OTHER          = 'other';

    public const ALL = [
        self::OIL_CHANGE, self::BATTERY_CHECK, self::FILTER_CHANGE,
        self::TYRE_SERVICE, self::TYRE_ROTATION, self::ALIGNMENT,
        self::BRAKE_SERVICE, self::AC_SERVICE,
        self::INSPECTION, self::DIAGNOSTICS, self::CLEANING,
        self::PROGRAMMING, self::REPAIR_LABOR, self::OTHER,
    ];

    public static function isValid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
