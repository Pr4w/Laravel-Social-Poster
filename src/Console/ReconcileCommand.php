<?php

namespace SocialPoster\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SocialPoster\Enums\Platform;
use SocialPoster\Poster;
use SocialPoster\ValueObjects\Credentials;

/**
 * Inspect or reconcile one publish by its idempotency key (the key PostFailed,
 * PostPublished and QueuedPost report). A published record is replayed:
 * PostPublished is dispatched again (with $replayed = true), so a listener that
 * failed the first time gets another go. The command has only the key, so the
 * replayed event carries the stored result (id, url, payload, state) and
 * $idempotencyKey, but no metadata and no correlation id: a listener that finds
 * its record through $event->result->metadata must also be able to find it by
 * $event->idempotencyKey (or replay from code with the builder, which passes
 * the post's metadata). Anything else is only reported: resuming an unfinished
 * publish needs the original post, which only the app has, so do that from
 * code with SocialPoster::on(...)->using(...)->...->idempotencyKey(...)->reconcile().
 */
class ReconcileCommand extends Command
{
    /** @var string */
    protected $signature = 'social-poster:reconcile
        {key : The idempotency key (PostFailed/PostPublished ::$idempotencyKey, QueuedPost::key()) or your own ->idempotencyKey()}
        {--platform= : The platform (needed for your own key, or for a record that is not published)}
        {--ref= : Use Credentials::ref(<ref>) for the replay, resolved by your CredentialsResolver}
        {--status : Only show what is recorded; dispatch nothing}';

    /** @var string */
    protected $description = 'Show the recorded status of a social-poster publish and replay a published result (PostPublished is dispatched again).';

    public function handle(Poster $poster): int
    {
        $key = (string) $this->argument('key');
        $platformOption = $this->option('platform');
        $platform = null;

        if (is_string($platformOption) && $platformOption !== '') {
            $platform = Platform::tryFrom(strtolower(trim($platformOption)));

            if ($platform === null) {
                $this->error("Unknown platform [{$platformOption}].");

                return self::FAILURE;
            }
        }

        $ref = $this->option('ref');
        $credentials = is_string($ref) && $ref !== '' ? Credentials::ref($ref) : null;
        $status = $poster->status($key, $platform, $credentials);

        $this->line('Status: '.($status ?? 'nothing recorded'));

        if ($status === 'published') {
            $result = $poster->outcome($key, $platform, $credentials);

            if ($result !== null) {
                $this->line('Post: '.($result->platformPostId ?? '(no id)').($result->url !== null ? ' '.$result->url : ''));
            }
        }

        if ($this->option('status') || $status === null) {
            return $status === null ? self::FAILURE : self::SUCCESS;
        }

        if ($status !== 'published') {
            $this->warn("The publish is '{$status}'. Resuming it needs the original post: call "
                .'SocialPoster::on(...)->using(...)->caption(...)->idempotencyKey(...)->reconcile() from your app, '
                .'or check the platform before re-posting.');

            return self::FAILURE;
        }

        try {
            $action = $poster->reconcile($key, $platform, $credentials);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($action === Poster::RECONCILE_REPLAYED) {
            $this->info('Replay queued: PostPublished will be dispatched again (replayed = true).');
            $this->line('It carries the stored result and the idempotency key, but no metadata and no correlation id: '
                .'listeners must find their record by $event->idempotencyKey. To replay with metadata, call '
                .'SocialPoster::on(...)->using(...)->withMetadata(...)->idempotencyKey(...)->reconcile() from your app.');

            return self::SUCCESS;
        }

        $this->warn("Nothing was dispatched ({$action}).");

        return self::FAILURE;
    }
}
