<?php

namespace App\Models;

use App\Support\ExpenseType;
use App\Support\OdooDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * One canonical expense type's two answers: which Odoo account it is charged to, and which Odoo document
 * records it. See the create_expense_type_mappings migration for why they are separate columns.
 *
 * The row is the authority, not config/odoo.php — that file only SEEDS the row once. Reading the config
 * at runtime would quietly undo every change Finance makes in the mappings screen, which is the exact
 * failure §4 warns about ("configuration defaults, NOT hardcoded business rules").
 */
class ExpenseTypeMapping extends Model
{
    protected $table = 'expense_type_mappings';

    protected $fillable = [
        'expense_type', 'label',
        'odoo_account_id', 'odoo_account_code', 'odoo_account_name',
        'odoo_document_type', 'odoo_journal_id', 'requires_attachment',
        'active', 'mapped_by', 'mapped_at', 'notes',
    ];

    protected $casts = [
        'active'              => 'boolean',
        'odoo_account_id'     => 'integer',
        'odoo_journal_id'     => 'integer',
        'requires_attachment' => 'boolean',
        'mapped_at'           => 'datetime',
    ];

    /**
     * What this expense type demands before it may be sent — the document type's defaults, with this
     * row's attachment override applied.
     *
     * The override is three-state on purpose (see the migration): null means "follow the document
     * type", which is the normal case and keeps the policy in ONE place. Only a type Finance has
     * deliberately singled out carries true or false.
     *
     * @return array{supplier:bool,invoice_number:bool,invoice_date:bool,attachment:bool}
     */
    public function requirements(): array
    {
        $needs = OdooDocumentType::requirements($this->odoo_document_type);

        if ($this->requires_attachment !== null) {
            $needs['attachment'] = (bool) $this->requires_attachment;
        }

        return $needs;
    }

    /**
     * Is the ACCOUNT side resolved? An id is the real identifier; a code is accepted because some
     * finance teams configure across Odoo databases by chart-of-accounts code, where ids differ.
     * The NAME never counts — §2 is explicit that names are display only.
     */
    public function hasAccount(): bool
    {
        return $this->odoo_account_id !== null
            || ($this->odoo_account_code !== null && trim((string) $this->odoo_account_code) !== '');
    }

    public function hasDocumentType(): bool
    {
        return OdooDocumentType::isValid($this->odoo_document_type);
    }

    /** Everything Odoo needs from this mapping is present and the category is switched on. */
    public function isUsable(): bool
    {
        return $this->active && $this->hasAccount() && $this->hasDocumentType();
    }

    public function displayLabel(): string
    {
        return $this->label ?: ExpenseType::label($this->expense_type);
    }
}
