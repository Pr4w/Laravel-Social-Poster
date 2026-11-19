<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fresh installs get the full 1.1 schema here; tables created by the v1.0
 * version of this migration are upgraded by add_claim_columns (2026_11_20),
 * which skips every column that already exists.
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
        $table = config('social.idempotency_table', 'social_idempotency');
        $schema = Schema::connection($this->getConnection());

        // Re-publishing the migrations must not fail on an existing table.
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, function (Blueprint $table) {
            $table->id();
            // The raw key, or "sha256:<hash>" when the key is longer than 255 characters.
            $table->string('key')->unique();
            // sha256 of the raw key.
            $table->char('key_hash', 64)->nullable()->unique();
            $table->string('status')->index(); // pending | committing | published | failed
            $table->string('platform')->nullable();
            // The driver's accountKey(): a key reused for another account is a collision.
            $table->string('account')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->text('url')->nullable();
            // PostResult::$state (published, draft, scheduled...) and $idKind, restored on replay.
            $table->string('result_state', 32)->nullable();
            $table->string('id_kind', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('state')->nullable();
            // The claim lease: who runs this key, and until when.
            $table->string('owner', 100)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            // When the record first became 'committing' (a create may have been sent).
            $table->timestamp('committing_since')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())
            ->dropIfExists(config('social.idempotency_table', 'social_idempotency'));
    }
};
