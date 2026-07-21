<?php

namespace Tests\Crud;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Broad read-surface sweep: hit every parameter-less GET endpoint in the app as a
 * super-admin and assert none returns a 5xx server error. This is the "nothing is
 * broken or blows up in the demo" guard across all ~70 read surfaces (dashboards,
 * boards, insights, financial, maintenance, logistics …).
 *
 * A 200 is ideal; a 403 (feature-flagged, e.g. simulation) or 422 is still "handled".
 * Only a 5xx counts as a failure — that's a real crash the boss must never see.
 */
class ReadEndpointsSmokeTest extends CrudTestCase
{
    /**
     * Every param-less GET endpoint. External-pull endpoints that reach the live
     * OfficeManager API (Sync/preview, TripDashboard/refresh) are covered leniently
     * in a separate test so a network blip never fails the core sweep.
     */
    public static function endpointProvider(): array
    {
        $endpoints = [
            '/api/user',
            '/api/Dashboard', '/api/Dashboard/fleet-pulse', '/api/Dashboard/overdue-maintenance',
            '/api/Dashboard/overdue-rentals', '/api/Dashboard/proactive-flags', '/api/Dashboard/trends',
            '/api/Vehicle', '/api/Vehicle/utilization', '/api/Vehicle/active-shop-stays',
            '/api/Vehicle/maintenance-overlaps', '/api/Vehicle/mileage-reconciliation',
            '/api/Driver', '/api/Vendor', '/api/Customer',
            '/api/Contract', '/api/Contract/exchanges/pending', '/api/Contract/next-no',
            '/api/Invoice', '/api/Invoice/status-summary', '/api/Payment',
            '/api/Activity', '/api/Fleet/expiring', '/api/Fleet/life-status',
            '/api/DataHealth', '/api/FinancialConflicts', '/api/FuelMileage',
            '/api/fault-causes', '/api/finding-keywords',
            '/api/Inspections', '/api/InspectionSchedules', '/api/inspector-pad',
            '/api/ServiceReminders', '/api/ContactReminders',
            '/api/logistics', '/api/logistics/assignees', '/api/logistics/drivers',
            '/api/logistics/my-queue', '/api/logistics/pool',
            '/api/Maintenance/analytics', '/api/Maintenance/approvals', '/api/Maintenance/board',
            '/api/Maintenance/booking-conflicts', '/api/Maintenance/cost-capture', '/api/Maintenance/events',
            '/api/Maintenance/foresight', '/api/Maintenance/garages', '/api/Maintenance/incidents',
            '/api/Maintenance/issue-history', '/api/Maintenance/reasons', '/api/Maintenance/recurring',
            '/api/maintenance-swaps/board',
            '/api/maintenance-tickets', '/api/maintenance-tickets/assignable-drivers',
            '/api/maintenance-tickets/board', '/api/maintenance-tickets/findings-catalog',
            '/api/maintenance-tickets/my-queue', '/api/maintenance-tickets/pending-invoices',
            '/api/MileageChain/audit', '/api/Profitability', '/api/readiness',
            '/api/intelligence/cost',
            '/api/Reconciliation', '/api/Reconciliation/fleet',
            '/api/Registration', '/api/Registration/coverage',
            '/api/simulation/status', '/api/StatusMismatch',
            '/api/Sync/audit',
            '/api/team/presence', '/api/TripDashboard',
            '/api/vehicle-status', '/api/vehicle-status/history',
            '/api/notifications', '/api/notifications/poll',
        ];

        return array_map(fn ($e) => [$e], $endpoints);
    }

    #[DataProvider('endpointProvider')]
    public function test_read_endpoint_does_not_crash(string $endpoint): void
    {
        $res = $this->getJson($endpoint);

        $this->assertLessThan(
            500,
            $res->getStatusCode(),
            "GET $endpoint returned {$res->getStatusCode()} (server error). Body: " . substr($res->getContent(), 0, 400)
        );
    }
}
