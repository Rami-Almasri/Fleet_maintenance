<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\FinancialConflictService;

class FinancialConflictController extends Controller
{
    /**
     * "Financial Conflicts" — the accounting clean-up hub. Returns ONLY broken invoices,
     * grouped by conflict type (VAT math, invoice↔contract mismatch, overlapping billing)
     * with example rows and counts, so the books can be reconciled.
     */
    public function index(FinancialConflictService $conflicts)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $conflicts->all(),
                "Financial conflicts retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
