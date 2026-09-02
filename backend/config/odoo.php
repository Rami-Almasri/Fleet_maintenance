<?php

use App\Support\ExpenseType;
use App\Support\OdooDocumentType;

return [
    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Odoo speaks JSON-RPC at POST {url}/jsonrpc. Everything here comes from the
    | environment — never from source, migrations, seeders or the frontend. With
    | no credentials set the integration stays DORMANT: nothing syncs, and every
    | financial event simply reports "Odoo is not configured" instead of failing
    | in a way that looks like a mapping problem.
    |
    */
    'url'      => env('ODOO_URL'),
    'database' => env('ODOO_DATABASE'),
    'username' => env('ODOO_USERNAME'),
    'password' => env('ODOO_PASSWORD'),

    // Odoo's JSON-RPC endpoint is stable across 14→18; the version is carried for the
    // few places where object semantics changed (see OdooClient::serverVersion()).
    'api_version' => env('ODOO_API_VERSION', '17.0'),

    'timeout'         => (int) env('ODOO_TIMEOUT', 30),
    'connect_timeout' => (int) env('ODOO_CONNECT_TIMEOUT', 10),
    // Transport-level retries only (connection refused / 5xx). A retry NEVER risks a duplicate
    // document because every write is guarded by the idempotency ref — see OdooDocumentPusher.
    'retries'        => (int) env('ODOO_RETRIES', 2),
    'retry_sleep_ms' => (int) env('ODOO_RETRY_SLEEP_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Company / currency
    |--------------------------------------------------------------------------
    */
    'company_id' => env('ODOO_COMPANY_ID') ? (int) env('ODOO_COMPANY_ID') : null,
    'currency'   => env('ODOO_CURRENCY', 'AED'),

    /*
    |--------------------------------------------------------------------------
    | Expense claimant
    |--------------------------------------------------------------------------
    |
    | An Odoo hr.expense is always SOMEBODY's claim and cannot be created without
    | an employee. FleetView users are not Odoo HR records and there is no mapping
    | between them, so the claimant for company-paid expenses (registration fees,
    | taxi fares booked centrally) is named here. Left null, every EXPENSE-type
    | event blocks with `expense_employee_missing` — which is correct: inventing an
    | employee id would file a real claim against the wrong person.
    |
    */
    'expense_employee_id' => env('ODOO_EXPENSE_EMPLOYEE_ID') ? (int) env('ODOO_EXPENSE_EMPLOYEE_ID') : null,

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Every document we create carries this prefix + the FinancialEvent id in Odoo's
    | own `ref` field. Before creating anything we SEARCH for that ref. That is what
    | makes a retry after a lost response link the existing document instead of
    | creating a second one. Changing this prefix orphans previously-synced events,
    | so it is configuration, not something to edit casually.
    |
    */
    'external_ref_prefix' => env('ODOO_EXTERNAL_REF_PREFIX', 'FLEETVIEW-FE-'),

    /*
    |--------------------------------------------------------------------------
    | Web URL template
    |--------------------------------------------------------------------------
    |
    | Used ONLY to build a link a user can click to open the document in Odoo. Null
    | means no link is shown — we never invent a URL. {id} and {model} are replaced.
    |
    */
    'document_url_template' => env('ODOO_DOCUMENT_URL_TEMPLATE'),

    /*
    |--------------------------------------------------------------------------
    | Expense type defaults
    |--------------------------------------------------------------------------
    |
    | THESE ARE SEED DEFAULTS, NOT BUSINESS RULES. They are written into the
    | `expense_type_mappings` table once by ExpenseTypeMappingSeeder; from then on
    | Finance edits the rows (account + document type) through the mappings admin
    | and this file is never consulted again. That is the whole point: an expense
    | ACCOUNT (what it is charged to) and a DOCUMENT TYPE (how it is recorded) are
    | independent, and either can change without a code deploy.
    |
    | `account_name` is DISPLAY ONLY. The identifier is `account_id` (an Odoo
    | account.account id) or `account_code` — both left null here because inventing
    | them would be a fabricated Odoo id. Finance resolves them from real Odoo
    | master data via `odoo:pull-master-data` and the mappings screen.
    |
    */
    'expense_type_defaults' => [
        ExpenseType::REPAIR => [
            'label'         => 'Repair maintenance',
            'account_name'  => 'Fleets Maintenance | Repair Maintenance Expenses',
            'document_type' => OdooDocumentType::VENDOR_BILL,
        ],
        ExpenseType::ROUTINE => [
            'label'         => 'Routine maintenance',
            'account_name'  => 'Fleets Maintenance | Routine Maintenance Expenses',
            'document_type' => OdooDocumentType::VENDOR_BILL,
        ],
        ExpenseType::RECOVERY => [
            'label'         => 'Recovery & towing',
            'account_name'  => 'Fleets Maintenance | Recovery Maintenance Expenses',
            'document_type' => OdooDocumentType::VENDOR_BILL,
        ],
        ExpenseType::FUEL => [
            'label'         => 'Fuel for maintenance',
            'account_name'  => 'Fuel Expenses | Fuel for Maintenance',
            'document_type' => OdooDocumentType::VENDOR_BILL,
        ],
        ExpenseType::REGISTRATION => [
            'label'         => 'Registration & licensing',
            'account_name'  => 'Fleet Admin Expenses | Registration',
            'document_type' => OdooDocumentType::EXPENSE,
        ],
        ExpenseType::CAR_WASH => [
            'label'         => 'Car washing',
            'account_name'  => 'Fleets Maintenance | Car Washing Expenses',
            'document_type' => OdooDocumentType::VENDOR_BILL,
        ],
        ExpenseType::TAXI => [
            'label'         => 'Public transportation — taxi',
            'account_name'  => 'Fleet Admin Expenses | Public Transportation - TAXI',
            'document_type' => OdooDocumentType::EXPENSE,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vehicle eligibility
    |--------------------------------------------------------------------------
    |
    | Which vehicles the financial integration concerns itself with. This is NOT a
    | status test ("Lent", "Employees" …) — a lent car still incurs repair cost that
    | belongs in Odoo. The rule is asset ownership: a vehicle FleetView is
    | responsible for maintaining. Vehicles the fleet has retired/disposed of no
    | longer accrue new financial events, so they are excluded from the mapping
    | backlog (an existing mapping is never deleted — history stays readable).
    |
    */
    'vehicle_eligibility' => [
        'exclude_statuses' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ODOO_VEHICLE_EXCLUDE_STATUSES', 'sold,disposed,retired'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Master data pull
    |--------------------------------------------------------------------------
    |
    | Odoo is authoritative for accounts, products, partners and analytic accounts.
    | We cache them locally ONLY so the mapping UI has something to pick from; the
    | cache is never used as an accounting source.
    |
    */
    'master_data' => [
        'batch_size' => (int) env('ODOO_MASTER_DATA_BATCH', 500),
        // A cached reference older than this is shown as stale in the mappings screen.
        'stale_after_hours' => (int) env('ODOO_MASTER_DATA_STALE_HOURS', 168),
    ],
];
