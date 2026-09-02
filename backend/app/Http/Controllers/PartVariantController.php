<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\PartVariantPerformanceService;
use Illuminate\Http\Request;

/**
 * "Which one should we actually buy?" — part variants compared on what they cost per month of
 * service, not on what they cost at the counter.
 *
 * Read-only, and gated on parts.view: this is a buying question, and the people who answer it are
 * the ones who raise the purchase.
 *
 * @see \App\Services\PartVariantPerformanceService for what is measured and what is refused
 */
class PartVariantController extends Controller
{
    public function __construct(private PartVariantPerformanceService $variants)
    {
    }

    /** The part types with enough history behind them to be worth opening, by spend. */
    public function index(Request $request)
    {
        try {
            $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';

            return ResponseHelper::SuccessResponse(
                $this->variants->partTypeIndex($locale),
                'Part types retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Every variant of one part type — the comparison itself. */
    public function show(int $part, Request $request)
    {
        try {
            $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';

            return ResponseHelper::SuccessResponse(
                $this->variants->forPartType($part, $locale),
                'Part variants retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
