<?php

namespace SocialPoster\Idempotency;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PostResult;
use Throwable;

/**
 * Database-backed idempotency store: durable across cache flushes and deploys.
 * Opt in with config('social.idempotency') and publish the migrations:
 *
 *   php artisan vendor:publish --tag=social-poster-migrations
 *
 * Claims are atomic: claim() inserts the row (insertOrIgnore on a unique
 * column; on SQL Server, which has none, an insert whose unique-key violation
 * is caught) or takes it over with one conditional UPDATE (status pending or
 * committing, and the lease free, expired or already ours), and checks the
 * affected-row count. Two workers can never both hold a key.
 *
 * Rows are looked up by the `key` column: the raw key, or "sha256:<hash>" when
 * the key is longer than 255 characters (v1.0 failed the insert after a live
 * publish in that case). `key_hash` holds the sha256 of every raw key and is
 * unique too; lookups stay on `key` so rows written by a worker that has not yet
 * seen the 1.1 migration are still found.
 *
 * Tables created by the v1.0 migration keep working before the 1.1 migration
 * runs: the store detects the missing columns (re-checking every minute), logs
 * one warning, takes leases through cache locks (social.lock_store) instead,
 * keeps the result state and committing time inside the JSON columns, and skips
 * the account binding.
 *
 * Connection: social.idempotency_connection (null = default).
 */
final class DatabaseIdempotencyStore implements AtomicIdempotencyStore
{
    /** Columns added by the 1.1 migration. */
    private const CLAIM_COLUMNS = ['key_hash', 'owner', 'lease_until', 'account', 'attempts', 'result_state', 'id_kind', 'committing_since'];

    /** Reserved key for what a v1.0-shaped table has no column for (inside payload/state JSON). */
    private const ENVELOPE = '__social_poster';

    private const SCHEMA_RECHECK_SECONDS = 60;

    private const MAX_KEY_LENGTH = 255;

    private ?bool $upgraded = null;

    private int $schemaCheckedAt = 0;

    private bool $warned = false;

    private ?bool $insertOrIgnore = null;

    public function __construct(
        private readonly string $table = 'social_idempotency',
        private readonly ?string $connection = null,
    ) {}

    public function completed(string $key): ?PostResult
    {
        $row = $this->find($key);

        if ($row === null || ($row['status'] ?? null) !== self::STATUS_PUBLISHED || ($row['platform'] ?? null) === null) {
            return null;
        }

        $payload = $this->decode($row['payload'] ?? null);
        $extra = is_array($payload[self::ENVELOPE] ?? null) ? $payload[self::ENVELOPE] : [];
        unset($payload[self::ENVELOPE]);

        try {
            return PostResult::fromArray([
                'platform' => $row['platform'],
                'platform_post_id' => $row['platform_post_id'] ?? null,
                'url' => $row['url'] ?? null,
                'payload' => $payload,
                'state' => $row['result_state'] ?? $extra['state'] ?? null,
                'id_kind' => $row['id_kind'] ?? $extra['id_kind'] ?? null,
            ]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function markPublished(string $key, PostResult $result): void
    {
        $payload = $result->payload;
        $values = [
            'status' => self::STATUS_PUBLISHED,
            'platform' => $result->platform->value,
            'platform_post_id' => $result->platformPostId,
            'url' => $result->url,
            'state' => null,
            'updated_at' => now(),
        ];

        if ($this->upgraded()) {
            $values += [
                'result_state' => $result->state,
                'id_kind' => $result->idKind,
                'owner' => null,
                'lease_until' => null,
                'committing_since' => null,
            ];
        } elseif ($result->state !== null || $result->idKind !== null) {
            $payload[self::ENVELOPE] = ['state' => $result->state, 'id_kind' => $result->idKind];
        }

        $values['payload'] = $this->encode($payload);

        // A published record may be rewritten (the same publish recorded twice)
        // but is the one write that may overwrite any other status.
        $this->upsert($key, $values, keepPublished: false);
    }

    public function pendingState(string $key): ?array
    {
        $row = $this->find($key);

        if ($row === null || ($row['status'] ?? null) !== self::STATUS_PENDING) {
            return null;
        }

        $state = $this->decode($row['state'] ?? null);
        unset($state[self::ENVELOPE]);

        return $state === [] && ($row['state'] ?? null) === null ? null : $state;
    }

    public function markPending(string $key, array $state): void
    {
        $values = ['status' => self::STATUS_PENDING, 'state' => $this->encode($state), 'updated_at' => now()];

        if ($this->upgraded()) {
            $values['committing_since'] = null;
        }

        $this->upsert($key, $values, keepPublished: true);
    }

    public function forget(string $key): void
    {
        $this->rows($key)->where('status', '!=', self::STATUS_PUBLISHED)->delete();

        if (! $this->upgraded() && $this->status($key) !== self::STATUS_PUBLISHED) {
            $this->releaseCacheLease($key);
        }
    }

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        if (! $this->upgraded()) {
            return $this->claimWithCacheLock($key, $owner, $leaseSeconds);
        }

        $now = now();
        $until = $now->copy()->addSeconds(max(1, $leaseSeconds));

        if ($this->insert($key, [
            'status' => self::STATUS_PENDING,
            'owner' => $owner,
            'lease_until' => $until,
            'attempts' => 1,
            'updated_at' => $now,
        ])) {
            return true;
        }

        // One statement decides the race: whoever's UPDATE matches wins, and the
        // attempts increment guarantees a changed row (MySQL counts changed rows).
        return $this->rows($key)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_COMMITTING])
            ->where(function (Builder $query) use ($owner, $now) {
                $query->whereNull('owner')
                    ->orWhere('owner', $owner)
                    ->orWhereNull('lease_until')
                    ->orWhere('lease_until', '<', $now);
            })
            ->increment('attempts', 1, $this->identity($key) + [
                'owner' => $owner,
                'lease_until' => $until,
                'updated_at' => $now,
            ]) > 0;
    }

    public function release(string $key, string $owner): void
    {
        if (! $this->upgraded()) {
            $this->cacheLease()->release($this->leaseName($key), $owner);

            return;
        }

        $this->rows($key)->where('owner', $owner)->update(['owner' => null, 'lease_until' => null]);
    }

    public function markCommitting(string $key, array $state): void
    {
        $now = now();

        if (! $this->upgraded()) {
            $row = $this->find($key);

            if (($row['status'] ?? null) === self::STATUS_PUBLISHED) {
                return;
            }

            // Keep the first time on a repeat and on a reopened failed record,
            // as the upgraded table does with committing_since.
            $previous = in_array($row['status'] ?? null, [self::STATUS_COMMITTING, self::STATUS_FAILED], true)
                ? $this->envelopeSince($row)
                : null;

            $state[self::ENVELOPE] = ['since' => is_int($previous) ? $previous : $now->getTimestamp()];

            $this->upsert($key, ['status' => self::STATUS_COMMITTING, 'state' => $this->encode($state), 'updated_at' => $now], keepPublished: true);

            return;
        }

        $this->upsert($key, [
            'status' => self::STATUS_COMMITTING,
            'state' => $this->encode($state),
            'updated_at' => $now,
        ], keepPublished: true, insertExtra: ['committing_since' => $now]);

        // Every other transition clears committing_since, so this only stamps a
        // record that just entered 'committing' and keeps the original time on
        // a repeat (the first create may already have been sent back then).
        $this->rows($key)
            ->where('status', self::STATUS_COMMITTING)
            ->whereNull('committing_since')
            ->update(['committing_since' => $now]);
    }

    public function committing(string $key): ?array
    {
        $row = $this->find($key);

        if ($row === null || ($row['status'] ?? null) !== self::STATUS_COMMITTING) {
            return null;
        }

        $state = $this->decode($row['state'] ?? null);
        $since = $state[self::ENVELOPE]['since'] ?? null;
        unset($state[self::ENVELOPE]);

        if (! is_int($since)) {
            $since = $this->timestamp($row['committing_since'] ?? null)
                ?? $this->timestamp($row['updated_at'] ?? null)
                ?? now()->getTimestamp();
        }

        return ['state' => $state, 'since' => $since];
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void
    {
        if (! $this->upgraded()) {
            return;
        }

        $platform = $platform instanceof Platform ? $platform->value : $platform;
        $account = self::fitted($account);
        $values = ['status' => self::STATUS_PENDING, 'platform' => $platform, 'account' => $account, 'updated_at' => now()];

        if ($this->insert($key, $values)) {
            return;
        }

        // Fill the binding on rows that have none yet (written by a claim, by
        // markPublished alone, or before 1.1). Each UPDATE is atomic, so of two
        // racing accounts exactly one gets recorded and the other sees it below.
        $this->rows($key)->whereNull('account')->update($this->identity($key) + ['account' => $account]);
        $this->rows($key)->whereNull('platform')->update(['platform' => $platform]);

        $row = $this->find($key);

        if ($row === null) {
            // Forgotten in between: nothing to collide with, record ours.
            $this->insert($key, $values);

            return;
        }

        $boundPlatform = isset($row['platform']) ? (string) $row['platform'] : null;

        if (($row['account'] ?? null) !== $account || ($boundPlatform !== null && $boundPlatform !== $platform)) {
            throw KeyCollisionException::make($key, $platform, $boundPlatform);
        }
    }

    public function markFailed(string $key, ?array $state = null): void
    {
        $values = ['status' => self::STATUS_FAILED, 'updated_at' => now()];

        if ($this->upgraded()) {
            if ($state !== null) {
                $values['state'] = $this->encode($state);
            }

            // committing_since stays: a reopened record keeps its first time.
            $values += ['owner' => null, 'lease_until' => null];
            $this->upsert($key, $values, keepPublished: true);

            return;
        }

        if ($state !== null) {
            // The v1.0 table keeps the committing time inside the state JSON:
            // carry it over, so a reconcile verifies from the original time.
            $since = $this->envelopeSince($this->find($key));

            if ($since !== null) {
                $state[self::ENVELOPE] = ['since' => $since];
            }

            $values['state'] = $this->encode($state);
        }

        $this->upsert($key, $values, keepPublished: true);
        $this->releaseCacheLease($key);
    }

    public function status(string $key): ?string
    {
        $status = $this->rows($key)->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * The whole record of a key, for Poster::reconcile() and diagnostics: a
     * 'failed' row keeps the state markFailed() was given, and nothing else
     * reads it back. The state comes without the store's internal envelope.
     * Null when nothing is recorded.
     *
     * @return array{status: string, state: ?array<string, mixed>, platform: ?string, account: ?string, since: ?int, updated_at: ?int}|null
     */
    public function record(string $key): ?array
    {
        $row = $this->find($key);

        if ($row === null || ! is_string($row['status'] ?? null)) {
            return null;
        }

        $state = ($row['state'] ?? null) === null ? null : $this->decode($row['state']);
        $since = is_array($state) && is_int($state[self::ENVELOPE]['since'] ?? null) ? $state[self::ENVELOPE]['since'] : null;

        if (is_array($state)) {
            unset($state[self::ENVELOPE]);
        }

        return [
            'status' => $row['status'],
            'state' => $state,
            'platform' => is_string($row['platform'] ?? null) ? $row['platform'] : null,
            'account' => is_string($row['account'] ?? null) ? $row['account'] : null,
            'since' => $row['status'] === self::STATUS_COMMITTING
                ? ($since ?? $this->timestamp($row['committing_since'] ?? null) ?? $this->timestamp($row['updated_at'] ?? null))
                : null,
            'updated_at' => $this->timestamp($row['updated_at'] ?? null),
        ];
    }

    /**
     * Delete records not updated for $days days, optionally only those with
     * the given statuses. Rows whose lease is still live are kept. Returns the
     * number of rows deleted (or that would be, with $dryRun).
     *
     * @param  list<string>  $statuses
     */
    public function prune(float $days, array $statuses = [], bool $dryRun = false, int $chunk = 1000): int
    {
        $cutoff = now()->subSeconds((int) round(max(0.0, $days) * 86400));
        $upgraded = $this->upgraded();

        $candidates = function () use ($cutoff, $statuses, $upgraded): Builder {
            $query = $this->query()->where('updated_at', '<', $cutoff);

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }

            if ($upgraded) {
                $query->where(fn (Builder $q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()));
            }

            return $query;
        };

        if ($dryRun) {
            return $candidates()->count();
        }

        $deleted = 0;
        $chunk = max(1, $chunk);

        do {
            $ids = $candidates()->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += $this->query()->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }

    /** Whether the configured table exists on the configured connection. */
    public function tableExists(): bool
    {
        return $this->schema()->hasTable($this->table);
    }

    /**
     * Update the row under the given guard, or insert it. When the insert loses
     * a race with a concurrent insert, the guarded update runs once more, so
     * the write is never silently dropped (and a published row never touched).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $insertExtra  Columns only set when the row is created.
     */
    private function upsert(string $key, array $values, bool $keepPublished, array $insertExtra = []): void
    {
        $update = function () use ($key, $values, $keepPublished): int {
            $query = $this->rows($key);

            if ($keepPublished) {
                $query->where('status', '!=', self::STATUS_PUBLISHED);
            }

            return $query->update($this->identity($key) + $values);
        };

        if ($update() > 0 || $this->insert($key, $values + $insertExtra)) {
            return;
        }

        $update();
    }

    /**
     * Insert the row unless one with the key exists. False when it exists.
     *
     * @param  array<string, mixed>  $values
     */
    private function insert(string $key, array $values): bool
    {
        $now = $values['updated_at'] ?? now();
        $row = ['key' => self::fitted($key)] + $this->identity($key) + $values + ['created_at' => $now, 'updated_at' => $now];

        if ($this->supportsInsertOrIgnore()) {
            return $this->query()->insertOrIgnore($row) > 0;
        }

        try {
            return $this->query()->insert($row);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Whether the grammar can compile insert-or-ignore (MySQL, MariaDB,
     * PostgreSQL, SQLite). SQL Server's cannot; there a plain insert whose
     * unique-key violation is caught does the same job. insertOrIgnore stays
     * the first choice: on PostgreSQL a failed insert would abort the
     * surrounding transaction.
     */
    private function supportsInsertOrIgnore(): bool
    {
        if ($this->insertOrIgnore === null) {
            try {
                $query = $this->query();
                $query->getGrammar()->compileInsertOrIgnore($query, [['key' => 'probe']]);
                $this->insertOrIgnore = true;
            } catch (Throwable) {
                $this->insertOrIgnore = false;
            }
        }

        return $this->insertOrIgnore;
    }

    /** @return array<string, mixed>|null */
    private function find(string $key): ?array
    {
        $row = $this->rows($key)->first();

        return $row === null ? null : (array) $row;
    }

    private function rows(string $key): Builder
    {
        return $this->query()->where('key', self::fitted($key));
    }

    private function query(): Builder
    {
        return $this->db()->table($this->table);
    }

    /** @return array<string, string> */
    private function identity(string $key): array
    {
        return $this->upgraded() ? ['key_hash' => hash('sha256', $key)] : [];
    }

    /** The value stored in a string(255) column: as is, or its sha256 when longer. */
    private static function fitted(string $value): string
    {
        return strlen($value) <= self::MAX_KEY_LENGTH ? $value : 'sha256:'.hash('sha256', $value);
    }

    /**
     * Whether the table has the 1.1 claim columns. A positive answer is kept
     * for the life of the store; a negative one is re-checked every minute so a
     * long-running worker picks the migration up without a restart.
     */
    private function upgraded(): bool
    {
        if ($this->upgraded === true) {
            return true;
        }

        if ($this->upgraded === false && time() - $this->schemaCheckedAt < self::SCHEMA_RECHECK_SECONDS) {
            return false;
        }

        $this->schemaCheckedAt = time();

        try {
            $columns = array_map('strtolower', $this->schema()->getColumnListing($this->table));
        } catch (Throwable) {
            $columns = [];
        }

        $this->upgraded = $columns !== [] && array_diff(self::CLAIM_COLUMNS, $columns) === [];

        if (! $this->upgraded) {
            $this->warnLegacy();
        }

        return $this->upgraded;
    }

    private function claimWithCacheLock(string $key, string $owner, int $leaseSeconds): bool
    {
        $lease = $this->cacheLease();
        $name = $this->leaseName($key);

        if (! $lease->acquire($name, $owner, $leaseSeconds)) {
            return false;
        }

        $status = $this->status($key);

        if ($status === self::STATUS_PUBLISHED || $status === self::STATUS_FAILED) {
            $lease->release($name, $owner);

            return false;
        }

        return true;
    }

    private function releaseCacheLease(string $key): void
    {
        try {
            $this->cacheLease()->forceRelease($this->leaseName($key));
        } catch (Throwable) {
            // Best effort: an unreleased lease only delays the next claim until it expires.
        }
    }

    private function cacheLease(): CacheLease
    {
        $store = config('social.lock_store');

        return new CacheLease(is_string($store) && $store !== '' ? $store : null);
    }

    private function leaseName(string $key): string
    {
        return 'social-poster:idempotency-lease:'.hash('sha256', $key);
    }

    private function warnLegacy(): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        try {
            Log::warning('social-poster: the idempotency table "'.$this->table.'" is missing the 1.1 columns ('
                .implode(', ', self::CLAIM_COLUMNS).'). Publish and run the package migrations. Until then claims use '
                .'cache locks (social.lock_store) and keys are not bound to accounts.');
        } catch (Throwable) {
            // Logging must never break a publish.
        }
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    private function schema(): SchemaBuilder
    {
        return DB::connection($this->connectionName())->getSchemaBuilder();
    }

    private function connectionName(): ?string
    {
        $name = $this->connection ?? config('social.idempotency_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @param  array<mixed>  $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * The committing time a v1.0-shaped table keeps inside the state JSON.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function envelopeSince(?array $row): ?int
    {
        $since = $this->decode($row['state'] ?? null)[self::ENVELOPE]['since'] ?? null;

        return is_int($since) ? $since : null;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof CarbonInterface) {
            return $value->getTimestamp();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
