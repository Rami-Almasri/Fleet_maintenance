<?php

namespace App\Exceptions;

use App\Models\FinancialEventAttempt;
use RuntimeException;

/**
 * Something went wrong talking to Odoo.
 *
 * The class carries a STABLE MACHINE CODE rather than relying on its message, because the message is
 * whatever Odoo said and is therefore unique per occurrence. The dashboard groups failures ("3 failed →
 * 2 timeout, 1 validation"), the retry policy branches on them, and neither can be built on prose.
 *
 * The distinction that matters most is between codes that are SAFE TO RETRY BLINDLY and codes that are
 * not — and the honest answer is that none of them are, which is why retrying never goes straight to a
 * create. Every code here can be raised by a call that DID reach Odoo and DID change something before
 * the connection broke; a timeout is the obvious case, but a validation error raised while writing the
 * second of two records is another. The idempotency search runs first on every attempt regardless of
 * which of these was thrown last, so classification drives reporting and backoff, never correctness.
 */
class OdooException extends RuntimeException
{
    /**
     * @param  string  $code  one of FinancialEventAttempt::ERROR_* — the stable grouping key
     * @param  array<string,mixed>  $context  extra diagnosis, never credentials
     */
    public function __construct(
        string $message,
        public string $errorCode = FinancialEventAttempt::ERROR_UNEXPECTED,
        public array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'Odoo is not configured — no connection details have been set for this environment.',
            FinancialEventAttempt::ERROR_NOT_CONFIGURED
        );
    }

    public static function authentication(string $message): self
    {
        return new self($message, FinancialEventAttempt::ERROR_AUTHENTICATION);
    }

    public static function timeout(string $message): self
    {
        return new self($message, FinancialEventAttempt::ERROR_TIMEOUT);
    }

    public static function network(string $message): self
    {
        return new self($message, FinancialEventAttempt::ERROR_NETWORK);
    }

    /** Odoo understood us and refused — a business/constraint error, not a transport one. */
    public static function validation(string $message, array $context = []): self
    {
        return new self($message, FinancialEventAttempt::ERROR_VALIDATION, $context);
    }

    /** Is this a transport problem that may resolve on its own, as opposed to a refusal? */
    public function isTransient(): bool
    {
        return in_array($this->errorCode, [
            FinancialEventAttempt::ERROR_TIMEOUT,
            FinancialEventAttempt::ERROR_NETWORK,
        ], true);
    }
}
