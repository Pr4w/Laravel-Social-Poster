<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Upgrades a table created by the v1.0 create migration to the 1.1 schema:
 * claim lease (owner, lease_until, attempts), account binding, the replayed
 * result's state and id kind, committing time, and a unique sha256 key_hash
 * backfilled from the existing keys. Purely additive and safe to re-run: every
 * column that already exists (fresh installs get them from the create
 * migration) is skipped. Until it runs, DatabaseIdempotencyStore keeps working
 * in a reduced mode (cache-lock claims, no account binding).
 */
return new class extends Migration
{
    /** Runs on social.idempotency_connection (null = default connection). */
    public function getConnection(): ?string
    {
        $connection = config('social.idempotency_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $table = $this->tableName();
        $schema = Schema::connection($this->getConnection());

        // Nothing to upgrade: the create migration builds the full schema.
        if (! $schema->hasTable($table)) {
            return;
        }

        $existing = array_map('strtolower', $schema->getColumnListing($table));
        $missing = fn (string $column): bool => ! in_array($column, $existing, true);

        $schema->table($table, function (Blueprint $blueprint) use ($missing) {
            if ($missing('key_hash')) {
                $blueprint->char('key_hash', 64)->nullable();
            }
            if ($missing('account')) {
                $blueprint->string('account')->nullable();
            }
            if ($missing('result_state')) {
                $blueprint->string('result_state', 32)->nullable();
            }
            if ($missing('id_kind')) {
                $blueprint->string('id_kind', 64)->nullable();
            }
            if ($missing('owner')) {
                $blueprint->string('owner', 100)->nullable();
            }
            if ($missing('lease_until')) {
                $blueprint->timestamp('lease_until')->nullable();
            }
            if ($missing('attempts')) {
                $blueprint->unsignedInteger('attempts')->default(0);
            }
            if ($missing('committing_since')) {
                $blueprint->timestamp('committing_since')->nullable();
            }
        });

        $this->backfillKeyHashes($table);

        if ($this->uniqueIndexOn($schema, $table, 'key_hash') === null) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->unique('key_hash'));
        }
    }

    public function down(): void
    {
        $table = $this->tableName();
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable($table)) {
            return;
        }

        $index = $this->uniqueIndexOn($schema, $table, 'key_hash');

        if ($index !== null) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($index));
        }

        $columns = array_values(array_intersect(
            ['key_hash', 'account', 'result_state', 'id_kind', 'owner', 'lease_until', 'attempts', 'committing_since'],
            array_map('strtolower', $schema->getColumnListing($table)),
        ));

        if ($columns !== []) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
        }
    }

    /**
     * key_hash = sha256 of the raw key. Keys longer than 255 characters were
     * stored as "sha256:<hash>" by 1.1 before this migration ran; their hash is
     * the stored one. Portable (no SHA2()/digest()): hashed in PHP, by id chunk.
     */
    private function backfillKeyHashes(string $table): void
    {
        $db = DB::connection($this->getConnection());

        $db->table($table)
            ->select(['id', 'key'])
            ->whereNull('key_hash')
            ->chunkById(500, function ($rows) use ($db, $table) {
                foreach ($rows as $row) {
                    $key = (string) $row->key;
                    $hash = preg_match('/^sha256:([0-9a-f]{64})$/', $key, $match) === 1
                        ? $match[1]
                        : hash('sha256', $key);

                    $db->table($table)->where('id', $row->id)->update(['key_hash' => $hash]);
                }
            });
    }

    private function uniqueIndexOn(Builder $schema, string $table, string $column): ?string
    {
        foreach ($schema->getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && array_map('strtolower', (array) ($index['columns'] ?? [])) === [$column]) {
                return (string) $index['name'];
            }
        }

        return null;
    }

    private function tableName(): string
    {
        return (string) config('social.idempotency_table', 'social_idempotency');
    }
};
