<?php

namespace App\Services;

use App\Models\Driver;

class DriverService
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
        $driver = Driver::with('user')->get();
        return $driver;
    }
    public function store(array $data)
    {
        $driver = Driver::create($data);
        return $driver;
    }
    public function update(array $data, Driver $driver)
    {
        $driver->update($data);
        return $driver->refresh();
    }
    public function destroy(Driver $driver)
    {
        $driver->delete();
    }
}
