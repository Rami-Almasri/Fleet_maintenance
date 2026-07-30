<?php

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ingest a document into the knowledge corpus so enrichment can cite it.
 *
 *   php artisan knowledge:ingest storage/manuals/camry-brakes.txt \
 *       --source=fleet_uploads --title="Toyota Camry Brake Service" \
 *       --type=service_manual --make=Toyota --model=Camry --year-from=2018
 *
 * This is the path that turns "the AI thinks" into "the manual says". Every chunk it writes becomes
 * retrievable by [[KnowledgeRetrievalService]], and any term or relationship generated from it
 * carries an [[EvidenceLink]] pointing back at the exact passage.
 *
 * ⚠️ INGEST ONLY WHAT YOU HAVE THE RIGHT TO. Purchased manuals, documents your fleet authored,
 * supplier material you're licensed for. ALLDATA / Mitchell 1 / Haynes / Chilton / OEM factory
 * manuals are copyrighted — ingest them only under your own subscription, and never by scraping.
 * The `access` column on the source records which footing each one is on.
 *
 * FORMAT: plain text or Markdown. PDFs are not parsed here — convert first (pdftotext, or any
 * extractor you trust) so the ingestion step stays dependency-free and predictable. Chunking splits
 * on blank lines and Markdown headings, keeping the heading with its body so a retrieved passage
 * still says which section it came from.
 */
class KnowledgeIngest extends Command
{
    protected $signature = 'knowledge:ingest
        {path : Path to a UTF-8 text or Markdown file}
        {--source=fleet_uploads : knowledge_sources.key this document belongs to}
        {--title= : Document title (defaults to the filename)}
        {--type=repair_guide : service_manual|tsb|repair_guide|spec_sheet|paper|regulation|internal}
        {--url= : Canonical URL, if the document has one}
        {--publisher= : Publisher / author}
        {--make= : Vehicle make this document applies to (omit for universal)}
        {--model= : Vehicle model}
        {--platform= : Platform / generation code}
        {--engine= : Engine code}
        {--year-from= : First model year covered}
        {--year-to= : Last model year covered}
        {--chunk=1200 : Target characters per chunk}
        {--replace : Re-ingest, replacing any existing document with the same checksum}';

    protected $description = 'Ingest a technical document into the knowledge corpus for grounded enrichment';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $source = KnowledgeSource::where('key', $this->option('source'))->first();
        if (! $source) {
            $this->error("Unknown source '{$this->option('source')}'. Run the KnowledgeSourceSeeder, or list with:");
            $this->line('  php artisan tinker --execute="App\Models\KnowledgeSource::pluck(\'key\')->each(fn($k)=>print($k.PHP_EOL));"');

            return self::FAILURE;
        }

        $text = file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8');
        }

        $checksum = hash('sha256', $text);
        $existing = KnowledgeDocument::where('checksum', $checksum)->first();

        if ($existing && ! $this->option('replace')) {
            $this->warn("Already ingested as document #{$existing->id} ({$existing->title}). Use --replace to re-ingest.");

            return self::SUCCESS;
        }

        $chunks = $this->chunk($text, (int) $this->option('chunk'));

        if ($chunks === []) {
            $this->error('No usable text found in that file.');

            return self::FAILURE;
        }

        $document = DB::transaction(function () use ($source, $path, $checksum, $chunks, $existing) {
            $existing?->delete();   // cascades its chunks

            $doc = KnowledgeDocument::create([
                'knowledge_source_id' => $source->id,
                'title'        => $this->option('title') ?: pathinfo($path, PATHINFO_FILENAME),
                'doc_type'     => in_array($this->option('type'), KnowledgeDocument::TYPES, true)
                                      ? $this->option('type') : KnowledgeDocument::TYPE_REPAIR_GUIDE,
                'url'          => $this->option('url'),
                'publisher'    => $this->option('publisher') ?: $source->publisher,
                'make'         => $this->option('make'),
                'model'        => $this->option('model'),
                'platform'     => $this->option('platform'),
                'engine'       => $this->option('engine'),
                'year_from'    => $this->option('year-from') ? (int) $this->option('year-from') : null,
                'year_to'      => $this->option('year-to') ? (int) $this->option('year-to') : null,
                'checksum'     => $checksum,
                'chunk_count'  => count($chunks),
                'ingested_at'  => now(),
            ]);

            foreach ($chunks as $i => $chunk) {
                KnowledgeChunk::create([
                    'knowledge_document_id' => $doc->id,
                    'ordinal' => $i,
                    'section' => $chunk['section'],
                    'heading' => $chunk['section'],
                    'text'    => $chunk['text'],
                ]);
            }

            return $doc;
        });

        $this->info("Ingested “{$document->title}” as document #{$document->id}");
        $this->line("  Source : {$source->name} ({$source->access})");
        $this->line('  Scope  : '.($document->scope_key === '*' ? 'all vehicles' : $document->scope_key));
        $this->line('  Chunks : '.count($chunks));
        $this->newLine();
        $this->line('Enrichment will now retrieve from this document. Re-run '
            .'<fg=cyan>keywords:enrich --force</> on the relevant keywords to ground them in it.');

        if (config('keyword_ai.embeddings.driver') === null) {
            $this->comment('No embeddings provider configured — retrieval will use lexical matching.');
        }

        return self::SUCCESS;
    }

    /**
     * Split into retrievable passages.
     *
     * Markdown headings become section labels and force a boundary — a retrieved passage that can
     * say "§5.2 Brake pad wear" is far more useful as a citation than an anonymous slice of text.
     * Otherwise paragraphs accumulate up to the target size, so a chunk is a coherent thought
     * rather than an arbitrary character window.
     *
     * @return array<int,array{section:?string,text:string}>
     */
    private function chunk(string $text, int $target): array
    {
        $target = max(300, min(4000, $target));
        $lines = preg_split('/\R/u', $text);

        $chunks = [];
        $section = null;
        $buffer = '';

        $flush = function () use (&$chunks, &$buffer, &$section) {
            $body = trim($buffer);
            if (mb_strlen($body) >= 40) {     // a stray line is not a passage
                $chunks[] = ['section' => $section, 'text' => $body];
            }
            $buffer = '';
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+)$/u', $line, $m)) {
                $flush();
                $section = mb_substr(trim($m[1]), 0, 300);
                continue;
            }

            $buffer .= $line."\n";

            // Break on a paragraph boundary once we're past target, so chunks end cleanly.
            if (mb_strlen($buffer) >= $target && trim($line) === '') {
                $flush();
            }
        }

        $flush();

        return $chunks;
    }
}
