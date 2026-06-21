<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow origin = 'api' (OfficeManager API) alongside 'web' and 'sheet'.
 */
return new class extends Migration
{
    private array $tables = ['customers', 'contracts', 'vehicles', 'vendors', 'vehicle_registrations'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN `origin` ENUM('web','sheet','api') NOT NULL DEFAULT 'web'");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            // revert any 'api' rows to 'sheet' so the narrower enum still fits
            DB::statement("UPDATE `{$t}` SET `origin` = 'sheet' WHERE `origin` = 'api'");
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN `origin` ENUM('web','sheet') NOT NULL DEFAULT 'web'");
        }
    }
};
