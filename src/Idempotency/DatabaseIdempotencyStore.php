<?php

namespace SocialPoster\Idempotency;

use Illuminate\Support\Facades\DB;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PostResult;

/**
 * Database-backed idempotency store. The unique key column gives an atomic claim,
 * and a published row lets a retry replay the recorded result instead of posting
 * again. Publish the migration with:
 *
 *   php artisan vendor:publish --tag=social-poster-migrations
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly string $table = 'social_idempotency') {}

    public function completed(string $key): ?PostResult
    {
        $row = DB::table($this->table)->where('key', $key)->where('status', 'published')->first();

        if ($row === null || $row->platform === null) {
            return null;
        }

        return PostResult::success(
            Platform::from($row->platform),
            $row->platform_post_id,
            $row->url,
            $row->payload ? (array) json_decode($row->payload, true) : [],
        );
    }

    public function markPublished(string $key, PostResult $result): void
    {
        DB::table($this->table)->updateOrInsert(['key' => $key], [
            'status' => 'published',
            'platform' => $result->platform->value,
            'platform_post_id' => $result->platformPostId,
            'url' => $result->url,
            'payload' => json_encode($result->payload),
            'state' => null,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function pendingState(string $key): ?array
    {
        $row = DB::table($this->table)->where('key', $key)->where('status', 'pending')->first();

        return $row && $row->state ? (array) json_decode($row->state, true) : null;
    }

    public function markPending(string $key, array $state): void
    {
        DB::table($this->table)->updateOrInsert(['key' => $key], [
            'status' => 'pending',
            'state' => json_encode($state),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function forget(string $key): void
    {
        DB::table($this->table)->where('key', $key)->delete();
    }
}
