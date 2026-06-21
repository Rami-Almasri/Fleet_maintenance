<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--path= : Directory to write the dump into (default: storage/app/backups)}';

    protected $description = 'Full mysqldump of the live database — the safety gate to run before any re-import.';

    public function handle(DatabaseBackup $backup): int
    {
        $this->info('Backing up the database…');

        try {
            $file = $backup->run($this->option('path') ?: null);
        } catch (Throwable $e) {
            $this->error('Backup FAILED — aborting. ' . $e->getMessage());

            return self::FAILURE;
        }

        $mb = number_format(filesize($file) / 1048576, 2);
        $this->info("Backup written: {$file} ({$mb} MB)");

        return self::SUCCESS;
    }
}
