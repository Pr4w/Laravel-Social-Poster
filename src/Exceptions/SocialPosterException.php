<?php

namespace SocialPoster\Exceptions;

use RuntimeException;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\QueueSafe;
use Throwable;

/**
 * Base for every package failure. $context carries structured diagnostics
 * (HTTP status, platform error codes, ids) plus a few reserved flags:
 *
 * - possibly_published (bool): the create call was ambiguous; the post may be live.
 * - terminal (bool): the credential can never recover (password changed, app removed).
 * - restart (bool, TemporaryException): drop the continuation state and start over.
 * - reresolve (bool, TemporaryException): re-resolve credentials before retrying.
 * - connection (bool): no HTTP response was received.
 * - request_sent (bool|null): for connection failures, whether the request left the client.
 *
 * $context is mutable through addContext() so mapError() can enrich an exception
 * in place without rebuilding it (which would lose file, line and trace).
 */
abstract class SocialPosterException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $context;

    public function __construct(
        string $message,
        public readonly ?Platform $platform = null,
        public readonly FailureReason $reason = FailureReason::Unknown,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        $this->context = $context;

        parent::__construct($message, 0, $previous);
    }

    /** Merge extra diagnostics into the context (existing keys win) and return $this. */
    public function addContext(array $extra): static
    {
        $this->context = $this->context + $extra;

        return $this;
    }

    /** Merge flags into the context, overriding existing keys, and return $this. */
    public function withFlags(array $flags): static
    {
        $this->context = $flags + $this->context;

        return $this;
    }

    /** True when the failed call may still have created the post on the platform. */
    public function possiblyPublished(): bool
    {
        return (bool) ($this->context['possibly_published'] ?? false);
    }

    /** True when the credential is permanently unusable and the account must be reconnected. */
    public function isTerminal(): bool
    {
        return (bool) ($this->context['terminal'] ?? false);
    }

    /**
     * Whether only reconnecting the account fixes this failure: a rejected
     * token or a missing permission (FailureReason::requiresReconnect()),
     * unless the driver marked the refusal as one a reconnect cannot change
     * (context reconnect = false: a privacy level or licence the channel or
     * creator may not use, an app that is not audited). The jobs dispatch
     * CredentialsRejected only when this is true.
     */
    public function needsReconnect(): bool
    {
        return $this->reason->requiresReconnect() && ($this->context['reconnect'] ?? true) !== false;
    }

    /**
     * A serialisable summary for queued listeners and logs (exceptions do not
     * serialise well). Context keeps scalars, nulls and arrays of them at any
     * depth; objects and closures are dropped wherever they are nested, so the
     * summary always survives serialize() and json_encode().
     *
     * 'category' is FailureReason::category(), except that a failure that may
     * have published (possibly_published) is always 'verify': check the
     * platform before posting again, and a permission refusal that a reconnect
     * cannot fix (context reconnect = false, see needsReconnect()) is
     * 'user_fix'. The reason still tells the cause.
     *
     * @return array{platform: ?string, reason: string, category: string, message: string, class: string, context: array<string, mixed>}
     */
    public function errorSummary(): array
    {
        return [
            'platform' => $this->platform?->value,
            'reason' => $this->reason->value,
            'category' => match (true) {
                $this->possiblyPublished() => 'verify',
                $this->reason->requiresReconnect() && ! $this->needsReconnect() => 'user_fix',
                default => $this->reason->category(),
            },
            'message' => $this->getMessage(),
            'class' => static::class,
            'context' => QueueSafe::plain($this->context),
        ];
    }
}
