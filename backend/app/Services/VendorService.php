<?php

namespace App\Services;

use App\Models\Vendor;

class VendorService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    public function index()
    {
        // include how many cars each vendor insures (relevant for insurance vendors)
        $vendor = Vendor::withCount('registrations')->get();
        return $vendor;
    }
    public function store(array $data)
    {
        $vendor = Vendor::create($data);
        return $vendor;
    }
    public function update(array $data, Vendor $vendor)
    {
        $vendor->update($data);
        return $vendor->refresh();
    }
    public function destroy(Vendor $vendor)
    {
        $vendor->delete();
    }
}
