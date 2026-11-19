<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

/**
 * What ->post() returns: the correlation id shared by every platform of the
 * call (the ->idempotencyKey() when one was set, else a fresh UUID) and what
 * happened to each platform at dispatch time. The publish outcomes still
 * arrive later, through PostPublished / PostFailed, which carry the same
 * correlation id and the per-platform idempotency key.
 *
 * - $platforms: a publish job was queued.
 * - $deduplicated: an identical post (same idempotency key) is already queued,
 *   so nothing new was queued (PostDeduplicated was dispatched).
 * - $failed: nothing was queued for the platform: it failed validation under
 *   ->bestEffort(), has no driver, or the queue push threw. PostFailed was
 *   dispatched for each.
 *
 * $idempotencyKeys holds the store key of each queued or deduplicated
 * platform: the key SocialPoster::outcome() and SocialPoster::reconcile()
 * accept, and the one the events report.
 *
 * @implements Arrayable<string, mixed>
 */
final class QueuedPost implements Arrayable, JsonSerializable
{
    /**
     * @param  list<Platform>  $platforms  Platforms whose publish job was queued.
     * @param  array<string, string>  $idempotencyKeys  Store key per platform value.
     * @param  list<Platform>  $deduplicated  Platforms already queued under the same key.
     * @param  array<string, SocialPosterException>  $failed  Why nothing was queued, per platform value.
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly array $platforms = [],
        public readonly array $idempotencyKeys = [],
        public readonly array $deduplicated = [],
        public readonly array $failed = [],
    ) {}

    /** True when a publish job was queued for the platform by this call. */
    public function queued(Platform|string $platform): bool
    {
        return in_array(self::value($platform), array_map(static fn (Platform $p) => $p->value, $this->platforms), true);
    }

    /** True when the platform was skipped because the same post is already queued. */
    public function wasDeduplicated(Platform|string $platform): bool
    {
        return in_array(self::value($platform), array_map(static fn (Platform $p) => $p->value, $this->deduplicated), true);
    }

    /** The idempotency (store) key of a platform's publish, or null when none was computed. */
    public function key(Platform|string $platform): ?string
    {
        return $this->idempotencyKeys[self::value($platform)] ?? null;
    }

    /** Why nothing was queued for the platform, or null. */
    public function errorFor(Platform|string $platform): ?SocialPosterException
    {
        return $this->failed[self::value($platform)] ?? null;
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /** @return array{correlation_id: string, platforms: list<string>, idempotency_keys: array<string, string>, deduplicated: list<string>, failed: array<string, array<string, mixed>>} */
    public function toArray(): array
    {
        $failed = [];

        foreach ($this->failed as $platform => $error) {
            $failed[$platform] = $error->errorSummary();
        }

        return [
            'correlation_id' => $this->correlationId,
            'platforms' => array_map(static fn (Platform $p) => $p->value, $this->platforms),
            'idempotency_keys' => $this->idempotencyKeys,
            'deduplicated' => array_map(static fn (Platform $p) => $p->value, $this->deduplicated),
            'failed' => $failed,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function value(Platform|string $platform): string
    {
        return $platform instanceof Platform ? $platform->value : strtolower(trim($platform));
    }
}
