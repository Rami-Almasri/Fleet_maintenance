<?php

namespace Database\Seeders;

use App\Models\ExpenseTypeMapping;
use App\Support\ExpenseType;
use Illuminate\Database\Seeder;

/**
 * Puts one row on the board for each canonical expense type, carrying the DEFAULTS from config/odoo.php.
 *
 * What it writes: the type, its label, the default document type, and the account NAME as a label so
 * Finance can see which account each type is meant to point at.
 *
 * What it deliberately does NOT write: `odoo_account_id`. Seeding an id would be inventing an Odoo
 * database id, which §26 and the top-level rules forbid outright — and an invented id is worse than a
 * blank one, because a blank one blocks loudly (`expense_account_unresolved` on the sync dashboard)
 * while a wrong one posts real money to the wrong account. Finance resolves the account against real
 * pulled master data on the mappings screen.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. Re-running never overwrites a decision somebody has made: a row that
 * already exists is left exactly as it is. That is what makes this safe to run on a deploy — the config
 * file seeds the board once and is never consulted again (see ExpenseTypeMapping's docblock).
 */
class ExpenseTypeMappingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = (array) config('odoo.expense_type_defaults', []);

        foreach (ExpenseType::ALL as $type) {
            $spec = $defaults[$type] ?? [];

            ExpenseTypeMapping::firstOrCreate(
                ['expense_type' => $type],
                [
                    'label'              => $spec['label'] ?? ExpenseType::label($type),
                    // Display-only until Finance resolves the real account. Carried so the mappings
                    // screen can show which account this type is INTENDED to reach.
                    'odoo_account_name'  => $spec['account_name'] ?? null,
                    'odoo_document_type' => $spec['document_type'] ?? null,
                    'active'             => true,
                ]
            );
        }
    }
}
