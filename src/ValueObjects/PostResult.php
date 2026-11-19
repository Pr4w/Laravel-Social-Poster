<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\QueueSafe;
use SocialPoster\Exceptions\SocialPosterException;

/**
 * The outcome of a publish for one platform. $payload carries what was actually
 * sent and/or the raw platform response, which is what PostPublished hands back.
 * $metadata is the caller's own correlation data from ->withMetadata(); the
 * package never reads it, it just carries it back so you can tie a result to
 * whatever you track internally (a scheduled_post_id, a job uuid, and so on).
 *
 * $state says what kind of success it is: published (live), draft (e.g. TikTok
 * inbox upload), scheduled (Facebook/YouTube scheduled publish), private, or
 * processing (accepted, not live yet). Null means the driver did not say, which
 * is how every v1.0 driver reports a live post. A scheduled or draft result must
 * not get a first comment or a permalink fetch.
 *
 * $idKind names what $platformPostId is when a platform has several ids for one
 * post (e.g. 'publish_id' or 'video_id' on TikTok, 'urn' on LinkedIn), so
 * metrics and permalinks can tell them apart.
 *
 * @implements Arrayable<string, mixed>
 */
final class PostResult implements Arrayable, JsonSerializable
{
    public const STATE_PUBLISHED = 'published';

    public const STATE_DRAFT = 'draft';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_PRIVATE = 'private';

    public const STATE_PROCESSING = 'processing';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @param  ?string  $state  One of the STATE_* constants, or null when the driver does not say.
     * @param  ?string  $idKind  What $platformPostId is (publish_id, video_id, urn...), when ambiguous.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly bool $success,
        public readonly ?string $platformPostId = null,
        public readonly ?string $url = null,
        public readonly array $payload = [],
        public readonly ?SocialPosterException $error = null,
        public readonly array $metadata = [],
        public readonly ?string $state = null,
        public readonly ?string $idKind = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function success(
        Platform $platform,
        ?string $platformPostId = null,
        ?string $url = null,
        array $payload = [],
        ?string $state = null,
        ?string $idKind = null,
    ): self {
        return new self($platform, true, $platformPostId, $url, $payload, null, [], $state, $idKind);
    }

    public static function failed(Platform $platform, SocialPosterException $error): self
    {
        return new self($platform, false, error: $error);
    }

    /** A live post: successful, and either reported as published or not qualified (v1.0 drivers). */
    public function isPublished(): bool
    {
        return $this->success && ($this->state === null || $this->state === self::STATE_PUBLISHED);
    }

    /** Accepted for a later publish time; not live yet. */
    public function isScheduled(): bool
    {
        return $this->state === self::STATE_SCHEDULED;
    }

    /** Uploaded as a draft the account owner still has to publish (e.g. TikTok inbox). */
    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    /** Accepted but still being processed by the platform; it may go live later. */
    public function isProcessing(): bool
    {
        return $this->state === self::STATE_PROCESSING;
    }

    /**
     * Return a copy carrying the caller's correlation metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->platform,
            $this->success,
            $this->platformPostId,
            $this->url,
            $this->payload,
            $this->error,
            $metadata,
            $this->state,
            $this->idKind,
        );
    }

    /**
     * A plain, JSON-safe array of every field. This is the shape idempotency
     * stores persist and fromArray() restores. 'error' holds the exception's
     * errorSummary() for display only: fromArray() never rebuilds the exception.
     *
     * @return array{platform: string, success: bool, platform_post_id: ?string, url: ?string, state: ?string, id_kind: ?string, payload: array<string, mixed>, metadata: array<string, mixed>, error: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform->value,
            'success' => $this->success,
            'platform_post_id' => $this->platformPostId,
            'url' => $this->url,
            'state' => $this->state,
            'id_kind' => $this->idKind,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'error' => $this->error === null ? null : QueueSafe::summary($this->error, $this->platform),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rebuild a result from toArray() output or a stored row. Tolerant by design,
     * because stores keep rows across releases:
     *
     * - snake_case (toArray, database columns) and camelCase property names;
     * - payload/metadata as arrays or JSON strings (a database column);
     * - missing keys take their defaults, and 'success' defaults to true since
     *   only successful results are ever recorded as completed;
     * - 'error' is ignored (the result comes back with error = null).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException When 'platform' is missing or unknown.
     */
    public static function fromArray(array $data): self
    {
        $platform = $data['platform'] ?? null;
        $platform = $platform instanceof Platform
            ? $platform
            : (is_string($platform) ? Platform::tryFrom($platform) : null);

        if ($platform === null) {
            throw new InvalidArgumentException('PostResult::fromArray() needs a known platform, got '.var_export($data['platform'] ?? null, true).'.');
        }

        return new self(
            $platform,
            (bool) ($data['success'] ?? true),
            self::stringOrNull($data['platform_post_id'] ?? $data['platformPostId'] ?? null),
            self::stringOrNull($data['url'] ?? null),
            self::arrayFrom($data['payload'] ?? null),
            null,
            self::arrayFrom($data['metadata'] ?? null),
            self::stringOrNull($data['state'] ?? null),
            self::stringOrNull($data['id_kind'] ?? $data['idKind'] ?? null),
        );
    }

    /**
     * Keeps the result queueable (it rides inside PostPublished and the comment
     * job) even when the error's stack trace holds closures.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'platform' => $this->platform,
            'success' => $this->success,
            'platformPostId' => $this->platformPostId,
            'url' => $this->url,
            'payload' => (array) QueueSafe::value($this->payload),
            'error' => $this->error === null ? null : QueueSafe::throwable($this->error),
            'metadata' => (array) QueueSafe::value($this->metadata),
            'state' => $this->state,
            'idKind' => $this->idKind,
        ];
    }

    /**
     * Also restores results serialised by v1.0 (no state/idKind), which would
     * otherwise leave those readonly properties uninitialised.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $platform = $data['platform'] ?? null;

        $this->platform = $platform instanceof Platform ? $platform : Platform::from((string) $platform);
        $this->success = (bool) ($data['success'] ?? false);
        $this->platformPostId = self::stringOrNull($data['platformPostId'] ?? null);
        $this->url = self::stringOrNull($data['url'] ?? null);
        $this->payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $this->error = ($data['error'] ?? null) instanceof SocialPosterException ? $data['error'] : null;
        $this->metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $this->state = self::stringOrNull($data['state'] ?? null);
        $this->idKind = self::stringOrNull($data['idKind'] ?? null);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array<string, mixed> */
    private static function arrayFrom(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
