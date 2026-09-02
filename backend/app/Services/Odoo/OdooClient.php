<?php

namespace App\Services\Odoo;

use App\Exceptions\OdooException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY place in the application that talks to Odoo over the wire.
 *
 * Odoo exposes JSON-RPC at POST {url}/jsonrpc. Two services matter:
 *
 *   common.login       (db, user, password) → uid
 *   object.execute_kw  (db, uid, password, model, method, args, kwargs) → whatever the model returns
 *
 * Everything above this class works in terms of {@see search()}, {@see read()}, {@see create()} and
 * friends, and never sees a transport detail. No controller calls Odoo (§24), and no business rule
 * knows what JSON-RPC is.
 *
 * ── WHAT THIS CLASS REFUSES TO DO ──────────────────────────────────────────────────────────────────
 *
 * It never invents data. With no credentials configured it throws {@see OdooException::notConfigured()}
 * on the first call rather than returning empty results, because an empty result is indistinguishable
 * from "Odoo genuinely has none of these" and would let the integration report success while doing
 * nothing. A dormant integration must be visibly dormant.
 *
 * It never logs a secret. The password is passed as a positional RPC argument, which is exactly the
 * kind of thing that ends up in an exception trace, so {@see redact()} is applied to everything before
 * it reaches a log line or an attempt row (§25, §38).
 *
 * ── RETRIES ────────────────────────────────────────────────────────────────────────────────────────
 *
 * Transport-level retries cover connection failures, and they apply to READS ONLY. {@see isWrite()} is
 * what enforces that, and the reason is worth stating plainly because it is not obvious:
 *
 * A `create` that times out may ALREADY HAVE COMMITTED in Odoo — that is the whole premise of §23.
 * Retrying it at the transport layer re-issues the create WITHOUT re-running the idempotency search,
 * because the search happens one level up in {@see OdooDocumentPusher}. So a transport retry on a write
 * is precisely the duplicate-bill scenario the design exists to prevent, arriving through the back door.
 * A write therefore gets exactly one attempt; if it fails, the failure is recorded and the RETRY goes
 * back through the pusher, which searches first and adopts whatever is already there.
 *
 * Reads have no such hazard — searching twice costs a round trip and nothing else.
 */
class OdooClient
{
    /** Cached for the life of the request — one login per process, not one per call. */
    private ?int $uid = null;

    private int $rpcId = 0;

    public function __construct(
        private ?string $url = null,
        private ?string $database = null,
        private ?string $username = null,
        private ?string $password = null,
    ) {
        $this->url      ??= (string) config('odoo.url');
        $this->database ??= (string) config('odoo.database');
        $this->username ??= (string) config('odoo.username');
        $this->password ??= (string) config('odoo.password');
    }

    /**
     * Is the integration usable at all?
     *
     * Every caller checks this BEFORE building a payload, so that "Odoo was never connected" surfaces as
     * its own blocking reason on the event rather than as a failed attempt that looks like Odoo's fault.
     */
    public function isConfigured(): bool
    {
        return $this->trimmed($this->url) !== null
            && $this->trimmed($this->database) !== null
            && $this->trimmed($this->username) !== null
            && $this->trimmed($this->password) !== null;
    }

    // ── Authentication ────────────────────────────────────────────────────────────────────────────

    /** Log in and return the Odoo user id, reusing it for the rest of the process. */
    public function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $this->assertConfigured();

        $result = $this->rpc('common', 'login', [$this->database, $this->username, $this->password]);

        // Odoo answers a bad login with `false`, not with an error object.
        if (! is_int($result) || $result <= 0) {
            throw OdooException::authentication(
                'Odoo rejected the configured credentials for database ' . $this->database . '.'
            );
        }

        return $this->uid = $result;
    }

    /**
     * A cheap round trip that proves the connection AND the credentials work.
     * Used by the health endpoint so an administrator can tell "not configured" from "wrong password".
     *
     * @return array{configured:bool, connected:bool, uid:?int, version:?string, error:?string}
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false, 'connected' => false, 'uid' => null, 'version' => null, 'error' => null];
        }

        try {
            $version = $this->serverVersion();
            $uid     = $this->uid();

            return ['configured' => true, 'connected' => true, 'uid' => $uid, 'version' => $version, 'error' => null];
        } catch (OdooException $e) {
            return [
                'configured' => true,
                'connected'  => false,
                'uid'        => null,
                'version'    => null,
                'error'      => $e->getMessage(),
            ];
        }
    }

    public function serverVersion(): ?string
    {
        $info = $this->rpc('common', 'version', []);

        return is_array($info) ? ($info['server_version'] ?? null) : null;
    }

    // ── The ORM surface ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, mixed>  $domain  an Odoo search domain, e.g. [['ref','=','FLEETVIEW-FE-x']]
     * @return list<int>
     */
    public function search(string $model, array $domain, int $limit = 0): array
    {
        $kwargs = $limit > 0 ? ['limit' => $limit] : [];
        $result = $this->executeKw($model, 'search', [$domain], $kwargs);

        return is_array($result) ? array_values(array_map('intval', $result)) : [];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     * @return list<array<string,mixed>>
     */
    public function read(string $model, array $ids, array $fields = []): array
    {
        if ($ids === []) {
            return [];
        }

        $result = $this->executeKw($model, 'read', [$ids], $fields ? ['fields' => $fields] : []);

        return is_array($result) ? $result : [];
    }

    /**
     * Search and read in one round trip — what the master-data pull uses.
     *
     * @return list<array<string,mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields = [], int $limit = 0, int $offset = 0): array
    {
        $kwargs = [];
        if ($fields) {
            $kwargs['fields'] = $fields;
        }
        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }
        if ($offset > 0) {
            $kwargs['offset'] = $offset;
        }

        $result = $this->executeKw($model, 'search_read', [$domain], $kwargs);

        return is_array($result) ? $result : [];
    }

    /** @param  array<string,mixed>  $values */
    public function create(string $model, array $values): int
    {
        $result = $this->executeKw($model, 'create', [$values]);

        if (! is_int($result) && ! (is_numeric($result) && (int) $result > 0)) {
            throw OdooException::validation(
                "Odoo did not return an id when creating a {$model} record.",
                ['model' => $model]
            );
        }

        return (int) $result;
    }

    /** @param  array<string,mixed>  $values */
    public function write(string $model, array $ids, array $values): bool
    {
        return (bool) $this->executeKw($model, 'write', [$ids, $values]);
    }

    /**
     * Call a model method — the escape hatch for the few document operations that are not plain CRUD
     * (posting a vendor bill is `action_post` on account.move, for instance).
     *
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->executeKw($model, $method, $args, $kwargs);
    }

    // ── Transport ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    private function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->uid();

        return $this->rpc('object', 'execute_kw', [
            $this->database,
            $uid,
            $this->password,
            $model,
            $method,
            $args,
            (object) $kwargs,   // an empty PHP array encodes as [], and Odoo requires a mapping here
        ], $this->isWrite($method));
    }

    /**
     * Does this model method CHANGE something in Odoo?
     *
     * Allow-listing the reads rather than the writes is deliberate: an unrecognised method is treated as
     * a write and therefore never auto-retried. Getting that backwards would mean a method nobody
     * thought about silently becomes retryable, and the cost of that mistake is a duplicate bill —
     * whereas the cost of being wrong in this direction is one lost retry on a read.
     */
    private function isWrite(string $method): bool
    {
        return ! in_array($method, ['search', 'read', 'search_read', 'search_count', 'fields_get', 'name_search'], true);
    }

    /**
     * One JSON-RPC round trip, with every failure mode turned into a classified {@see OdooException}.
     *
     * @param  array<int, mixed>  $args
     */
    private function rpc(string $service, string $method, array $args, bool $isWrite = false): mixed
    {
        $this->assertConfigured();

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => ['service' => $service, 'method' => $method, 'args' => $args],
            'id'      => ++$this->rpcId,
        ];

        try {
            $response = $this->request($isWrite)->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // Laravel raises ConnectionException for both a refused connection and an expired timeout;
            // the message is the only thing that tells them apart, and the distinction is worth keeping
            // because a timeout is the case where a document may exist despite the failure.
            $message = $e->getMessage();
            throw str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout')
                ? OdooException::timeout('Odoo did not respond in time: ' . $message)
                : OdooException::network('Could not reach Odoo: ' . $message);
        }

        if ($response->serverError()) {
            throw OdooException::network(
                'Odoo returned HTTP ' . $response->status() . '.',
                );
        }

        if ($response->clientError()) {
            throw OdooException::validation(
                'Odoo rejected the request with HTTP ' . $response->status() . '.'
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw OdooException::network('Odoo returned a response that was not JSON.');
        }

        if (isset($body['error'])) {
            throw $this->classifyRpcError($body['error']);
        }

        return $body['result'] ?? null;
    }

    /**
     * Turn Odoo's error object into a classified exception.
     *
     * Odoo reports almost everything — a missing required field, a broken constraint, a bad login — as a
     * generic RPC fault, so classification reads the fault NAME rather than the HTTP status. Anything
     * unrecognised is treated as a validation error rather than as a transport one, deliberately: a
     * validation error is not automatically retried, and silently re-sending something Odoo has already
     * refused for a reason we did not understand is the worse of the two mistakes.
     */
    private function classifyRpcError(array $error): OdooException
    {
        $data    = is_array($error['data'] ?? null) ? $error['data'] : [];
        $name    = (string) ($data['name'] ?? '');
        $message = trim((string) ($data['message'] ?? $error['message'] ?? 'Odoo returned an error.'));

        $context = ['odoo_error_name' => $name ?: null];

        if (str_contains($name, 'AccessDenied') || str_contains($name, 'AccessError')) {
            return OdooException::authentication($message !== '' ? $message : 'Odoo denied access.');
        }

        return OdooException::validation($message !== '' ? $message : 'Odoo returned an error.', $context);
    }

    /**
     * @param  bool  $isWrite  a write gets exactly ONE attempt — see the class docblock for why
     *                         auto-retrying a create is the duplicate-bill scenario in disguise.
     */
    private function request(bool $isWrite = false): PendingRequest
    {
        $retries = $isWrite ? 1 : max(1, (int) config('odoo.retries', 2));

        return Http::acceptJson()
            ->connectTimeout((int) config('odoo.connect_timeout', 10))
            ->timeout((int) config('odoo.timeout', 30))
            ->retry($retries, (int) config('odoo.retry_sleep_ms', 500), throw: false);
    }

    private function endpoint(): string
    {
        return rtrim((string) $this->url, '/') . '/jsonrpc';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw OdooException::notConfigured();
        }
    }

    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Strip anything secret out of a structure before it is logged or stored on an attempt row.
     *
     * The password travels as a positional RPC argument, so it cannot be recognised by key name — the
     * only reliable rule is to replace any value equal to it, wherever it appears and at any depth.
     * Key-based redaction is applied as well for the payloads that DO name their secrets.
     */
    public function redact(mixed $value): mixed
    {
        $secret = $this->trimmed($this->password);

        if (is_string($value)) {
            return $secret !== null && $value === $secret ? '***' : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && preg_match('/password|secret|token|api_key/i', $key)
                ? '***'
                : $this->redact($item);
        }

        return $out;
    }

    /** Structured log line for an integration event — never carries credentials. */
    public function log(string $message, array $context = []): void
    {
        Log::channel(config('logging.default'))->info('[odoo] ' . $message, $this->redact($context));
    }
}
