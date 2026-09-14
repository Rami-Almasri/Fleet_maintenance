<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Somebody confirmed this stage is done."
 *
 * THE REASON A NEW STAGE NEEDS NO MIGRATION. A rung the office invents tomorrow — Management
 * Approval, Insurer Inspection, Recovery Arranged — has no column of its own and needs none: its gate
 * is `manual_confirmation`, and satisfying it writes a row here. Without this table every new stage
 * would be a schema change and a deploy, which is precisely the developer dependency this feature
 * exists to remove.
 *
 * Attributed by construction: who confirmed it, when, and what they said. A confirmation with no name
 * on it would be indistinguishable from the system marking its own homework.
 */
class AccidentStageCompletion extends Model
{
    protected $fillable = [
        'accident_case_id', 'stage_key', 'completed_at',
        'completed_by', 'completed_by_name', 'note',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function accidentCase(): BelongsTo
    {
        return $this->belongsTo(AccidentCase::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
