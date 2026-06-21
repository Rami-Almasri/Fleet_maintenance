<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Full logical backup of the live MySQL database via mysqldump.
 *
 * This is the safety gate that must succeed before any re-import: callers run
 * it first and abort the whole operation if it throws. Credentials come from
 * the Laravel connection config (not the raw .env) so it works even when
 * DB_HOST/DB_DATABASE are left on their framework defaults.
 */
class DatabaseBackup
{
    /** Locations checked (in order) when mysqldump isn't on PATH. */
    protected array $knownDumpPaths = [
        'C:\xampp\mysql\bin\mysqldump.exe',
        'C:\wamp64\bin\mysql\mysql8.0\bin\mysqldump.exe',
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe',
        'C:\Program Files\MariaDB\bin\mysqldump.exe',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
    ];

    public function __construct(protected ?string $connection = null)
    {
        $this->connection = $connection ?: (string) config('database.default');
    }

    /**
     * Dump the database into $dir (default storage/app/backups) and return the file path.
     *
     * @throws RuntimeException when the connection isn't MySQL, mysqldump is missing,
     *                          the dump exits non-zero, or the output looks empty.
     */
    public function run(?string $dir = null): string
    {
        $cfg    = (array) config("database.connections.{$this->connection}");
        $driver = $cfg['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "db:backup only supports MySQL/MariaDB; connection '{$this->connection}' is '{$driver}'."
            );
        }

        $dump = $this->locateMysqldump();

        $dir = $dir ?: storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create backup directory: {$dir}");
        }

        $db   = (string) $cfg['database'];
        $file = $dir . DIRECTORY_SEPARATOR . $db . '-' . now()->format('Ymd-His') . '.sql';

        $args = [
            $dump,
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ($cfg['port'] ?? '3306'),
            '--user=' . ($cfg['username'] ?? 'root'),
            '--single-transaction',   // consistent snapshot without locking the 50k-row tables
            '--quick',                // stream rows instead of buffering the whole table
            '--routines',
            '--triggers',
            '--default-character-set=' . ($cfg['charset'] ?? 'utf8mb4'),
            '--result-file=' . $file, // mysqldump writes the file itself (no shell redirection)
            $db,
        ];

        // Pass the password via MYSQL_PWD so it never appears in the process list/logs.
        $env = [];
        if (($cfg['password'] ?? '') !== '') {
            $env['MYSQL_PWD'] = (string) $cfg['password'];
        }

        $result = Process::timeout(600)->env($env)->run($args);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump failed: ' . trim($result->errorOutput() ?: $result->output()));
        }

        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size < 1024) {
            throw new RuntimeException("Backup looks empty ({$size} bytes): {$file}");
        }

        return $file;
    }

    protected function locateMysqldump(): string
    {
        $explicit = (string) env('MYSQLDUMP_PATH', '');
        if ($explicit !== '' && is_file($explicit)) {
            return $explicit;
        }

        foreach ($this->knownDumpPaths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $finder = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        $found  = Process::run([$finder, 'mysqldump']);
        if ($found->successful()) {
            $first = trim(strtok($found->output(), "\n"));
            if ($first !== '' && is_file($first)) {
                return $first;
            }
        }

        throw new RuntimeException(
            'mysqldump not found. Set MYSQLDUMP_PATH in .env (e.g. C:\\xampp\\mysql\\bin\\mysqldump.exe).'
        );
    }
}
