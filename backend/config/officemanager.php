<?php

return [
    // OfficeManager API — the primary source of truth (contracts, vehicles, invoices…).
    'base_url' => env('OFFICEMANAGER_BASE_URL', 'http://81.85.92.150:8080'),
    'api_key'  => env('OFFICEMANAGER_API_KEY'),
    // The server struggles under load. Keep pages tiny and give slow responses room
    // to breathe; the retry backoff is deliberately long so we never hammer it.
    'timeout'        => (int) env('OFFICEMANAGER_TIMEOUT', 300),         // seconds to wait for a (slow) response body
    'connect_timeout' => (int) env('OFFICEMANAGER_CONNECT_TIMEOUT', 30), // seconds to establish the TCP connection
    'page_size'      => (int) env('OFFICEMANAGER_PAGE_SIZE', 25),
    // vehicles is a small, properly-paginated endpoint — use big pages (contracts don't paginate)
    'vehicles_page_size' => (int) env('OFFICEMANAGER_VEHICLES_PAGE_SIZE', 500),
    'retries'        => (int) env('OFFICEMANAGER_RETRIES', 3),
    'retry_sleep_ms' => (int) env('OFFICEMANAGER_RETRY_SLEEP_MS', 30000), // ≥30s between attempts

    // INTERACTIVE profile — used for live web requests (a user is waiting), e.g. the Financial-Layer
    // endpoints. Short timeout + single attempt so a slow/absent OM endpoint fails fast instead of
    // hanging the single-threaded `artisan serve` past PHP's 60s max_execution_time (which froze the
    // whole app whenever Profitability / Cost Intelligence loaded).
    'interactive_timeout'         => (int) env('OFFICEMANAGER_INTERACTIVE_TIMEOUT', 8),  // seconds for the response body
    'interactive_connect_timeout' => (int) env('OFFICEMANAGER_INTERACTIVE_CONNECT_TIMEOUT', 4), // seconds to connect

    // The /contracts endpoint IGNORES page/page_size (it returns the whole filtered set),
    // so we slice it by OutDate month-by-month instead. This is the earliest month a manual
    // back-fill (--from/--to) would reach; the normal sync no longer scans this far back.
    'contracts_since' => env('OFFICEMANAGER_CONTRACTS_SINCE', '2011-01-01'),

    // Closed-contract history depth, in months. 0 = ALL history (every contract our cars ever
    // had). Because contracts are now filtered to OUR cars only, we pull the full history by
    // default; set a positive N to keep only the last N months of closed contracts instead.
    'contracts_closed_months' => (int) env('OFFICEMANAGER_CONTRACTS_CLOSED_MONTHS', 0),

    // The /contracts and /vehicles endpoints are multi-tenant — they return EVERY car owner's
    // data, not just ours. We import only OUR cars/contracts: any car under one of these owner
    // numbers, PLUS any CarSerial in extra_car_serials below. 1541 is our company; comma-
    // separate OFFICEMANAGER_OWNER_NOS to add more fully-owned owner numbers.
    'owner_nos' => array_values(array_filter(array_map('trim', explode(',', (string) env('OFFICEMANAGER_OWNER_NOS', '1541'))))),
    // Individual cars we run under another owner number (so the whole owner isn't ours). e.g.
    // CarSerial 2155 = the one GMC YUKON we operate under owner 2088.
    'extra_car_serials' => array_values(array_filter(array_map('trim', explode(',', (string) env('OFFICEMANAGER_EXTRA_CAR_SERIALS', '2155'))))),
    // Master switch: set false to import all owners' contracts again (the old behaviour).
    'contracts_fleet_only' => (bool) env('OFFICEMANAGER_CONTRACTS_FLEET_ONLY', true),

    // The /sync web page is a READ-ONLY monitor — syncs run from the CLI (sync-fleet) on a
    // schedule, never from the browser. Keep this false so no one can start a sync/wipe from
    // the web. Set OFFICEMANAGER_WEB_SYNC_ENABLED=true only to re-enable the old web buttons.
    'web_sync_enabled' => (bool) env('OFFICEMANAGER_WEB_SYNC_ENABLED', false),

    // Sync Audit retention: how many days of sync_runs history to keep. After each healthy
    // live sync, runs older than this are deleted (cascading to sync_corrections/sync_changes),
    // so the audit feed stays tidy without manual cleanup. 0 = keep forever.
    'audit_retention_days' => (int) env('OFFICEMANAGER_AUDIT_RETENTION_DAYS', 90),
];
