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

    /**
     * @param bool $interactive  Live web request (a user is waiting). Uses a SHORT timeout and NO
     *   long retry storm so a slow/absent OM endpoint fails fast instead of hanging the single-threaded
     *   `artisan serve` past PHP's max_execution_time. The default (patient) profile — long timeout +
     *   30s-spaced retries — stays for BATCH sync commands, where a fragile server is worth waiting on.
     */
    protected function req(bool $interactive = false): PendingRequest
    {
        $connect = $interactive
            ? (int) config('officemanager.interactive_connect_timeout', 4)
            : (int) config('officemanager.connect_timeout', 30);
        $timeout = $interactive
            ? (int) config('officemanager.interactive_timeout', 8)
            : (int) config('officemanager.timeout', 300);
        $retries = $interactive ? 1 : (int) config('officemanager.retries', 3);
        $sleepMs = $interactive ? 0 : (int) config('officemanager.retry_sleep_ms', 30000);

        return Http::withHeaders(['X-API-Key' => (string) config('officemanager.api_key')])
            ->acceptJson()
            ->connectTimeout($connect)
            ->timeout($timeout)
            // Batch profile: the server is fragile under load, so wait a long time between attempts
            // (≥30s) — a retry storm never looks like an attack. Interactive profile: fail fast.
            ->retry($retries, $sleepMs, throw: false);
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
    public function fetchOnce(string $path, array $query = [], bool $interactive = false): array
    {
        $resp = $this->req($interactive)->get("{$this->base}/api/v1/{$path}", $query);

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

    /**
     * Accounting voucher HEADERS linked to a rental contract. The API joins on
     * RelativeRecordNo == ContractSerial (the contract record-type family), so pass the
     * contract's SERIAL, not its number. Headers only — there is NO debit/credit amount on a
     * voucher; presence proves the contract was posted to the books, nothing more. The filtered
     * result is tiny, so a single fetch is enough (no pagination needed).
     *
     * @param array{contract_serial?:int,from_date?:string,to_date?:string,group_no?:int,receipt_no?:int} $filters
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function accountsVouchers(array $filters = []): array
    {
        return $this->fetchOnce('accounts/vouchers', $filters);
    }

    /**
     * Cash-receipts journal — the ONLY amount-bearing accounting feed (Date, Customer, Amount,
     * Journal, Payment Type = Receive/Send). Filter by contract_no / customer_no (+ optional date
     * range). NOTE: the API's only_with_card flag defaults to TRUE (card receipts only); we force
     * it false here so cash/bank receipts are included too — pass it explicitly to override.
     *
     * @param array{contract_no?:int,customer_no?:int,from_date?:string,to_date?:string,only_with_card?:string} $filters
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function balanceReceipts(array $filters = []): array
    {
        return $this->fetchOnce('reports/balance', array_merge(['only_with_card' => 'false'], $filters));
    }

    /** Raw GET of an /api/v1 path returning the decoded JSON (for object endpoints that aren't lists). */
    public function get(string $path, array $query = [], bool $interactive = false): array
    {
        $resp = $this->req($interactive)->get("{$this->base}/api/v1/{$path}", $query);

        if (! $resp->ok()) {
            throw new RuntimeException("OfficeManager {$path} failed: HTTP {$resp->status()}");
        }

        return $resp->json() ?? [];
    }

}
