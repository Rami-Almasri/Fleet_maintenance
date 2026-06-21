<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the OfficeManager API (the primary source of truth).
 * Auth is an X-API-Key header; list endpoints page with ?page&page_size.
 */
class OfficeManagerClient
{
    protected string $base;
    protected int $pageSize;

    public function __construct()
    {
        $this->base = rtrim((string) config('officemanager.base_url'), '/');
        $this->pageSize = (int) config('officemanager.page_size', 500);

        if (! config('officemanager.api_key')) {
            throw new RuntimeException('OFFICEMANAGER_API_KEY is not set in .env');
        }
    }

    protected function req(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => (string) config('officemanager.api_key')])
            ->acceptJson()
            ->connectTimeout((int) config('officemanager.connect_timeout', 30))
            ->timeout((int) config('officemanager.timeout', 300))
            // The server is fragile under load. Wait a long time between attempts (≥30s by
            // default) so a retry storm never looks like an attack — stability over speed.
            ->retry(
                (int) config('officemanager.retries', 3),
                (int) config('officemanager.retry_sleep_ms', 30000),
                throw: false
            );
    }

    /** Raw status / health helpers. */
    public function health(): array
    {
        return $this->req()->get("{$this->base}/health")->json() ?? [];
    }

    /**
     * Stream every item from a paginated list endpoint, page by page.
     *
     * @return Generator<array<string,mixed>>
     */
    public function paginate(string $path, array $query = [], ?int $pageSize = null): Generator
    {
        $pageSize = $pageSize ?: $this->pageSize;
        $page = 1;
        do {
            $resp = $this->req()->get("{$this->base}/api/v1/{$path}", array_merge($query, [
                'page'      => $page,
                'page_size' => $pageSize,
            ]));

            if (! $resp->ok()) {
                throw new RuntimeException("OfficeManager {$path} page {$page} failed: HTTP {$resp->status()}");
            }

            $data = $resp->json();
            $items = $data['items'] ?? [];
            foreach ($items as $item) {
                yield $item;
            }

            $total = (int) ($data['total'] ?? 0);
            $totalPages = (int) ceil($total / $pageSize);
            $page++;
        } while ($page <= $totalPages && count($items) > 0);
    }

    /**
     * Fetch a list endpoint in ONE request, returning its items + reported total.
     * Use this for endpoints that ignore page/page_size (e.g. /contracts): paginating
     * them just re-downloads the same full result set, so we slice by filter instead.
     *
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function fetchOnce(string $path, array $query = []): array
    {
        $resp = $this->req()->get("{$this->base}/api/v1/{$path}", $query);

        if (! $resp->ok()) {
            throw new RuntimeException("OfficeManager {$path} failed: HTTP {$resp->status()}");
        }

        $data = $resp->json();

        return ['items' => $data['items'] ?? [], 'total' => (int) ($data['total'] ?? 0)];
    }

    /** Total count reported by a list endpoint (one cheap call). */
    public function count(string $path, array $query = []): int
    {
        $resp = $this->req()->get("{$this->base}/api/v1/{$path}", array_merge($query, ['page' => 1, 'page_size' => 1]));
        return (int) ($resp->json()['total'] ?? 0);
    }

    public function vehicles(): Generator
    {
        // The /vehicles endpoint IGNORES page/page_size — it returns the whole ~2.3k-row set on
        // every call, so paginating just re-downloads the same rows N times (page 1 == page 2).
        // Fetch it ONCE and yield each row a single time.
        $page = $this->fetchOnce('vehicles');
        foreach ($page['items'] as $item) {
            yield $item;
        }
    }

    /** @param array{from_date?:string,status_no?:int} $filters */
    public function contracts(array $filters = []): Generator
    {
        return $this->paginate('contracts', $filters);
    }

    /** @param array{from_date?:string,customer_no?:int} $filters */
    public function invoices(array $filters = []): Generator
    {
        return $this->paginate('invoices', $filters);
    }
}
