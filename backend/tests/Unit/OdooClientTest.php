<?php

namespace Tests\Unit;

use App\Exceptions\OdooException;
use App\Models\FinancialEventAttempt;
use App\Services\Odoo\OdooClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The transport layer's contract with everything above it.
 *
 * These matter because the layer above BRANCHES on the classification: a timeout is the case where a
 * document may exist despite the failure, an authentication error is a configuration problem nobody
 * should retry into, and a validation error is Odoo saying no for a reason. If every failure arrived as
 * a generic exception, the dashboard's "3 failed → 2 timeout, 1 validation" would be "3 failed", and the
 * retry policy would have nothing to reason about.
 *
 * No database. These run in the default suite.
 */
class OdooClientTest extends TestCase
{
    private function client(): OdooClient
    {
        return new OdooClient('https://odoo.example.test', 'fleetdb', 'bot@fleet.test', 's3cret');
    }

    /** A client with no credentials is DORMANT and says so — it never quietly returns empty results. */
    public function test_an_unconfigured_client_reports_itself_and_refuses_to_call(): void
    {
        $client = new OdooClient('', '', '', '');

        $this->assertFalse($client->isConfigured());
        $this->assertSame(
            ['configured' => false, 'connected' => false, 'uid' => null, 'version' => null, 'error' => null],
            $client->health()
        );

        Http::fake();

        try {
            $client->search('account.move', []);
            $this->fail('an unconfigured client must refuse to call Odoo');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_NOT_CONFIGURED, $e->errorCode);
        }

        // The important half: it did not merely throw, it never touched the network.
        Http::assertNothingSent();
    }

    public function test_a_rejected_login_is_an_authentication_failure(): void
    {
        // Odoo answers a bad login with `false` in the result, NOT with an error object — the exact
        // shape that would otherwise sail through as a successful call returning nothing.
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => false])]);

        try {
            $this->client()->uid();
            $this->fail('a false login result must not be treated as success');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_AUTHENTICATION, $e->errorCode);
        }
    }

    public function test_an_odoo_fault_is_classified_as_a_validation_error(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2])
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'error' => [
                    'message' => 'Odoo Server Error',
                    'data'    => ['name' => 'odoo.exceptions.ValidationError', 'message' => 'Partner is required.'],
                ]]),
        ]);

        try {
            $this->client()->create('account.move', ['move_type' => 'in_invoice']);
            $this->fail('an Odoo fault must surface as an exception');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_VALIDATION, $e->errorCode);
            // The message Odoo gave, not a generic one — this is what a user reads on a failed event.
            $this->assertSame('Partner is required.', $e->getMessage());
            $this->assertFalse($e->isTransient(), 'a refusal must not be treated as a transient fault');
        }
    }

    public function test_an_access_error_is_classified_as_authentication_not_validation(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2])
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'error' => [
                    'data' => ['name' => 'odoo.exceptions.AccessError', 'message' => 'You are not allowed to create this.'],
                ]]),
        ]);

        try {
            $this->client()->create('account.move', []);
            $this->fail('expected an exception');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_AUTHENTICATION, $e->errorCode);
        }
    }

    /**
     * A timeout is the failure that matters most to classify correctly: it is the one case where the
     * request may have REACHED Odoo and changed something before we gave up listening.
     *
     * Deliberately its own test rather than sharing one with the refused-connection case below —
     * Http::fake() ACCUMULATES stubs rather than replacing them, so two fakes in one method would leave
     * the first one answering both calls and the second assertion would silently test nothing.
     */
    public function test_a_timeout_is_classified_as_a_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));

        try {
            $this->client()->uid();
            $this->fail('expected an exception');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_TIMEOUT, $e->errorCode);
            $this->assertTrue($e->isTransient());
        }
    }

    /** A connection that was never established is a network fault, not a timeout. */
    public function test_a_refused_connection_is_classified_as_a_network_fault(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to odoo.example.test'));

        try {
            $this->client()->uid();
            $this->fail('expected an exception');
        } catch (OdooException $e) {
            $this->assertSame(FinancialEventAttempt::ERROR_NETWORK, $e->errorCode);
            $this->assertTrue($e->isTransient());
        }
    }

    /**
     * §25/§38 — the password travels as a positional RPC argument, so it cannot be recognised by key
     * name. Redaction has to find it by VALUE, at any depth, or it ends up in an attempt row.
     */
    public function test_the_password_is_redacted_wherever_it_appears(): void
    {
        $client = $this->client();

        $redacted = $client->redact([
            'service' => 'object',
            'args'    => ['fleetdb', 2, 's3cret', 'account.move', 'create'],
            'nested'  => ['deeper' => ['s3cret']],
            'api_key' => 'something-else-entirely',
        ]);

        $this->assertSame('***', $redacted['args'][2], 'the positional password must be redacted');
        $this->assertSame('***', $redacted['nested']['deeper'][0], 'redaction must reach any depth');
        $this->assertSame('***', $redacted['api_key'], 'a secret-looking KEY is redacted regardless of value');
        // Everything that is not a secret survives, or the attempt row would be useless for diagnosis.
        $this->assertSame('fleetdb', $redacted['args'][0]);
        $this->assertSame('account.move', $redacted['args'][3]);
    }

    public function test_search_returns_integer_ids_and_read_short_circuits_on_an_empty_set(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2])
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [18452, 18453]]),
        ]);

        $client = $this->client();
        $this->assertSame([18452, 18453], $client->search('account.move', [['ref', '=', 'X']]));

        // read([]) must not produce an RPC — an empty id list has one correct answer and it is local.
        Http::fake();
        $this->assertSame([], $client->read('account.move', []));
        Http::assertNothingSent();
    }
}
