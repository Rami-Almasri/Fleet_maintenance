<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Find text that was destroyed by a non-UTF8 write, and prove whether it can still happen.
 *
 * WHAT THIS DETECTS. When a string is written to MySQL over a connection whose charset cannot
 * represent it, the server does not fail — it silently substitutes a literal '?' (0x3F) for every
 * character it cannot encode. Arabic written over a latin1 connection therefore lands as a row of
 * question marks, and the original bytes are GONE. Nothing can decode them back afterwards, because
 * there is nothing left to decode: `??????` is six question marks in storage, not mis-tagged Arabic.
 *
 * That is why this command reports rather than repairs. A row it names has to be re-imported from
 * whatever system it came from; there is no transformation that recovers it.
 *
 * The heuristic is deliberately narrow — a run of question marks separated only by spaces and
 * punctuation. A single '?' is usually just a question mark, and flagging it would bury the real
 * damage in noise.
 *
 * It also round-trips a known Arabic string through the live connection, because the useful question
 * is not only "what was lost" but "can this still happen". A clean round-trip means the damage is
 * historical and the write path is safe today.
 */
class EncodingCheckCommand extends Command
{
    protected $signature = 'data:encoding-check {--samples=5 : How many example rows to print per column}';

    protected $description = 'Find text destroyed by a non-UTF8 write, and verify the connection is clean today';

    /** The human-named text columns worth checking. Machine keys and ids cannot carry Arabic. */
    private const TARGETS = [
        ['vendors', 'name'],
        ['vendors', 'address'],
        ['part_purchases', 'source_name'],
        ['part_purchases', 'part_name'],
        ['part_invoices', 'supplier_name'],
        ['store_items', 'part_name'],
        ['maintenance_line_items', 'description'],
        ['component_catalog', 'name'],
    ];

    public function handle(): int
    {
        $this->line('');
        $this->info('Is the connection clean today?');

        $probe = 'شركة الدبلوماسية لقطع غيار السيارات';
        $back  = DB::selectOne('SELECT ? AS v', [$probe])->v;
        $clean = $probe === $back;

        $this->line(sprintf(
            '  round-trip of Arabic: %s',
            $clean ? '<fg=green>preserved — new writes are safe</>' : '<fg=red>DESTROYED — the write path is still corrupting</>'
        ));
        $this->line(sprintf('  database charset: %s', DB::selectOne('SELECT @@character_set_database c')->c));
        $this->line(sprintf('  client charset:   %s', DB::selectOne('SELECT @@character_set_client c')->c));

        $this->line('');
        $this->info('Damage already in the data (unrecoverable — re-import from source):');

        $samples = (int) $this->option('samples');
        $total = 0;

        foreach (self::TARGETS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // A run of two or more '?' separated only by spaces/dots — the signature of a destroyed
            // multi-character word, as opposed to somebody genuinely typing a question mark.
            $rows = DB::table($table)
                ->where($column, 'REGEXP', '[?][ .,()-]*[?]')
                ->limit(max($samples, 1))
                ->pluck($column);

            $count = DB::table($table)->where($column, 'REGEXP', '[?][ .,()-]*[?]')->count();

            if ($count === 0) {
                continue;
            }

            $total += $count;
            $this->line(sprintf('  <fg=yellow>%s.%s</> — %d row(s)', $table, $column, $count));
            foreach ($rows as $v) {
                $this->line('      ' . mb_strimwidth((string) $v, 0, 70, '…'));
            }
        }

        if ($total === 0) {
            $this->line('  <fg=green>none found</>');
        }

        $this->line('');
        $this->line(sprintf('%d damaged value(s) across %d column(s) checked.', $total, count(self::TARGETS)));

        // Non-zero only when the write path is STILL broken — that is the actionable failure. Damage
        // that already exists is a fact to report, not a reason to fail a scheduled check forever.
        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
