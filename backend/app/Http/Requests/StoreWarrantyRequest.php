<?php

namespace App\Http\Requests;

use App\Models\Warranty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording a warranty someone gave us.
 *
 * Shape only. The rules that need to look at other rows — the anchor must match the kind, the car
 * must agree with the anchor — live in WarrantyService, because they must hold for every writer,
 * not only for requests that happen to arrive through this endpoint.
 */
class StoreWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware owns permission
    }

    public function rules(): array
    {
        return [
            'kind'       => ['required', Rule::in(Warranty::KINDS)],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],

            // Anchors — nullable here, cross-checked against `kind` in the service.
            'part_purchase_id'     => ['nullable', 'exists:part_purchases,id'],
            'vehicle_component_id' => ['nullable', 'exists:vehicle_components,id'],
            'maintenance_task_id'  => ['nullable', 'exists:maintenance_tasks,id'],
            'maintenance_id'       => ['nullable', 'exists:maintenances,id'],

            'subject'              => ['nullable', 'string', 'max:300'],
            'component_catalog_id' => ['nullable', 'exists:component_catalog,id'],

            'provider_vendor_id' => ['nullable', 'exists:vendors,id'],
            'provider_name'      => ['nullable', 'string', 'max:255'],
            'reference_no'       => ['nullable', 'string', 'max:120'],

            // WHO honours it, and how to reach them. A vehicle warranty is claimed by telephoning a
            // service department and quoting a number, and the person doing that is rarely the person
            // who typed the warranty in — so the contact is part of the record, not a note.
            'provider_kind'  => ['nullable', Rule::in(Warranty::PROVIDER_KINDS)],
            'contact_name'   => ['nullable', 'string', 'max:160'],
            'contact_phone'  => ['nullable', 'string', 'max:60'],
            'contact_email'  => ['nullable', 'email', 'max:160'],

            /**
             * The itemised cover, as component_catalog ids. Both OPTIONAL and both meaningfully
             * absent: a booklet nobody has read line by line has neither, which yields UNKNOWN for
             * every part and routes the question to a human. Sending an empty array is a different
             * statement ("itemised, and nothing is on it") and is preserved as such.
             * @see \App\Models\Warranty::coversCatalog()
             */
            'covered_catalog_ids'    => ['nullable', 'array'],
            'covered_catalog_ids.*'  => ['integer', 'exists:component_catalog,id'],
            'excluded_catalog_ids'   => ['nullable', 'array'],
            'excluded_catalog_ids.*' => ['integer', 'exists:component_catalog,id'],
            'coverage_notes'         => ['nullable', 'string', 'max:4000'],

            'starts_on'      => ['required', 'date'],
            'start_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            // 600 months = 50 years. A cap that only catches a typed extra zero, not a real promise.
            'duration_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'duration_km'     => ['nullable', 'integer', 'min:0', 'max:2000000'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // A whole-car promise must name a car. The service enforces this too (it must, for every
            // writer), but catching it here turns a business-rule failure into a field error the form
            // can highlight, which is the difference between a red toast and a usable message.
            if ($this->input('kind') === Warranty::KIND_VEHICLE && ! $this->filled('vehicle_id')) {
                $v->errors()->add('vehicle_id', 'A manufacturer or dealer warranty is a promise about a specific car — pick the vehicle.');
            }

            // A part type cannot be both covered and excluded by the same promise. The engine already
            // resolves the contradiction safely (exclusion wins, because a document that contradicts
            // itself is one we lose the argument on), but a contradiction typed into a form is a
            // mistake, not a policy — refuse it while the person is still looking at the screen.
            $overlap = array_intersect(
                array_map('intval', (array) $this->input('covered_catalog_ids', [])),
                array_map('intval', (array) $this->input('excluded_catalog_ids', [])),
            );
            if ($overlap !== []) {
                $v->errors()->add('excluded_catalog_ids',
                    'The same part cannot be both covered and excluded by one warranty. Remove it from one of the two lists.');
            }

            // A warranty measured in kilometres needs the reading it starts from, or the distance
            // leg is uncomputable and the cover silently becomes time-only. Better to refuse now
            // than to discover it when a claim is being argued.
            if ($this->filled('duration_km') && (int) $this->input('duration_km') > 0
                && $this->input('start_odometer') === null) {
                $v->errors()->add(
                    'start_odometer',
                    'A distance warranty needs the odometer it starts from — without it, "20,000 km from when?" has no answer.'
                );
            }

            // Neither leg means a promise with no end. That is legitimate but rare, so it must be
            // deliberate: it is far more often someone who forgot to fill the window in.
            if (! $this->filled('duration_months') && ! $this->filled('duration_km')
                && ! $this->boolean('unlimited_confirmed')) {
                $v->errors()->add(
                    'duration_months',
                    'Give a length in months, in kilometres, or both. If this warranty genuinely never expires, send unlimited_confirmed=true.'
                );
            }
        });
    }
}
