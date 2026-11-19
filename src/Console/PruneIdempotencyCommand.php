<?php

namespace SocialPoster\Console;

use Illuminate\Console\Command;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;

/**
 * Keeps the DatabaseIdempotencyStore table from growing forever. A record only
 * matters while a retry, a redelivery or a repeated dispatch of the same key
 * can still happen, which is hours, not months. Schedule it daily:
 *
 *     Schedule::command('social-poster:prune-idempotency')->daily();
 *
 * Deletes rows not updated for --days (30), optionally only some statuses.
 * Rows whose claim lease is still live are always kept. Pruning a 'published'
 * row ends its replay protection, so keep --days well above your longest retry
 * or re-dispatch window. CacheIdempotencyStore needs none of this: its records
 * expire after social.idempotency_ttl.
 */
class PruneIdempotencyCommand extends Command
{
    protected $signature = 'social-poster:prune-idempotency
        {--days=30 : Delete records not updated for this many days}
        {--status=* : Only prune these statuses (pending, committing, published, failed)}
        {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete old records from the social-poster idempotency table';

    public function handle(): int
    {
        $days = $this->option('days');

        if (! is_numeric($days) || (float) $days < 0) {
            $this->error('--days must be a number of days, 0 or more.');

            return self::INVALID;
        }

        $statuses = array_values(array_filter(array_map('strval', (array) $this->option('status'))));
        $unknown = array_diff($statuses, AtomicIdempotencyStore::STATUSES);

        if ($unknown !== []) {
            $this->error('Unknown status: '.implode(', ', $unknown).'. Use: '.implode(', ', AtomicIdempotencyStore::STATUSES).'.');

            return self::INVALID;
        }

        $table = (string) config('social.idempotency_table', 'social_idempotency');
        $connection = config('social.idempotency_connection');
        $store = new DatabaseIdempotencyStore($table, is_string($connection) && $connection !== '' ? $connection : null);

        if (! $store->tableExists()) {
            $this->info("No idempotency table \"{$table}\": nothing to prune.");
            $this->noteCacheStore();

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $count = $store->prune((float) $days, $statuses, $dryRun);

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ').$count." idempotency record(s) from \"{$table}\".");
        $this->noteCacheStore();

        return self::SUCCESS;
    }

    protected function noteCacheStore(): void
    {
        $store = config('social.idempotency');

        if (is_string($store) && is_a($store, CacheIdempotencyStore::class, true)) {
            $this->line('The configured CacheIdempotencyStore expires its records after social.idempotency_ttl seconds; it needs no pruning.');
        }
    }
}
