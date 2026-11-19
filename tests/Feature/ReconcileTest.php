<?php

namespace SocialPoster\Tests\Feature\ReconcileTest;

use ArrayObject;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Poster;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

// --- Fakes --------------------------------------------------------------------

/** A Facebook driver running a script; unscripted calls publish 'fb-1'. */
class ReconcileDriver extends AbstractPlatform
{
    /** @var list<mixed> */
    public static array $script = [];

    /** @var list<array{method: string, state: ?array<string, mixed>, caption: ?string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$script = [];
        static::$calls = [];
    }

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(new CaptionRules(0, 5000), new MediaRules(0, 10));
    }

    public function validate(PreparedPost $post): MessageBag
    {
        return new MessageBag;
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        return $this->step('publish', $post, null);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->step('resume', $post, $state);
    }

    /** @param array<string, mixed>|null $state */
    protected function step(string $method, PreparedPost $post, ?array $state): PublishOutcome
    {
        static::$calls[] = ['method' => $method, 'state' => $state, 'caption' => $post->caption()];

        $step = static::$script !== [] ? array_shift(static::$script) : PublishOutcome::published(PostResult::success(Platform::Facebook, 'fb-1'));

        if ($step instanceof Closure) {
            $step = $step($post, $state);
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    }
}

/** An in-memory atomic store that does not expose record(), like a minimal custom store. */
class OpaqueStore implements AtomicIdempotencyStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function completed(string $key): ?PostResult
    {
        $row = $this->rows[$key] ?? null;

        return ($row['status'] ?? null) === self::STATUS_PUBLISHED ? $row['result'] : null;
    }

    public function markPublished(string $key, PostResult $result): void
    {
        $this->rows[$key] = ['status' => self::STATUS_PUBLISHED, 'result' => $result, 'state' => null] + ($this->rows[$key] ?? []);
    }

    public function pendingState(string $key): ?array
    {
        return ($this->rows[$key]['status'] ?? null) === self::STATUS_PENDING ? ($this->rows[$key]['state'] ?? null) : null;
    }

    public function markPending(string $key, array $state): void
    {
        if (($this->rows[$key]['status'] ?? null) !== self::STATUS_PUBLISHED) {
            $this->rows[$key] = ['status' => self::STATUS_PENDING, 'state' => $state] + ($this->rows[$key] ?? []);
        }
    }

    public function forget(string $key): void
    {
        if (($this->rows[$key]['status'] ?? null) !== self::STATUS_PUBLISHED) {
            unset($this->rows[$key]);
        }
    }

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        $status = $this->rows[$key]['status'] ?? null;

        if ($status === self::STATUS_PUBLISHED || $status === self::STATUS_FAILED) {
            return false;
        }

        $this->rows[$key] = ($this->rows[$key] ?? ['status' => self::STATUS_PENDING]);

        return true;
    }

    public function release(string $key, string $owner): void {}

    public function markCommitting(string $key, array $state): void
    {
        if (($this->rows[$key]['status'] ?? null) !== self::STATUS_PUBLISHED) {
            $this->rows[$key] = ['status' => self::STATUS_COMMITTING, 'state' => $state, 'since' => $this->rows[$key]['since'] ?? 1000] + ($this->rows[$key] ?? []);
        }
    }

    public function committing(string $key): ?array
    {
        $row = $this->rows[$key] ?? null;

        return ($row['status'] ?? null) === self::STATUS_COMMITTING ? ['state' => $row['state'] ?? [], 'since' => $row['since'] ?? 1000] : null;
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void
    {
        $this->rows[$key] = ($this->rows[$key] ?? ['status' => self::STATUS_PENDING]) + [
            'platform' => $platform instanceof Platform ? $platform->value : $platform,
            'account' => $account,
        ];
    }

    public function markFailed(string $key, ?array $state = null): void
    {
        if (($this->rows[$key]['status'] ?? null) !== self::STATUS_PUBLISHED) {
            $this->rows[$key] = ['status' => self::STATUS_FAILED] + ($state !== null ? ['state' => $state] : []) + ($this->rows[$key] ?? []);
        }
    }

    public function status(string $key): ?string
    {
        return $this->rows[$key]['status'] ?? null;
    }
}

/** The same store, also exposing record(), as reconcile() can use. */
class RecordingStore extends OpaqueStore
{
    /** @return array<string, mixed>|null */
    public function record(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }
}

// --- Helpers ------------------------------------------------------------------

/** @return array<string, string> */
function credentials(): array
{
    return ['account_id' => 'page-1', 'page_access_token' => 'fb-token'];
}

function keyFor(string $correlationId, string $caption = 'Hello'): string
{
    return PublishToConnectionJob::idempotencyKeyFor(Platform::Facebook, new SocialPost(caption: $caption), Credentials::make(credentials()), $correlationId);
}

function store(): IdempotencyStore
{
    return app(IdempotencyStore::class);
}

function recordPublished(): ArrayObject
{
    $log = new ArrayObject;

    Event::listen(PostPublished::class, function (PostPublished $event) use ($log) {
        $log[] = $event;
    });

    return $log;
}

beforeEach(function () {
    ReconcileDriver::reset();
    app(SocialManager::class)->extend('facebook', fn () => new ReconcileDriver);
    Carbon::setTestNow('2026-11-18 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Carbon::setTestNow();
});

// --- outcome() / status() -----------------------------------------------------

it('finds the published outcome by the store key, by the caller key with credentials, and by a v1.0 key', function () {
    $queued = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:1')->post();
    $key = $queued->key('facebook');

    store()->markPublished('legacy-1:facebook', PostResult::success(Platform::Facebook, 'fb-legacy'));

    expect($key)->toBe(keyFor('schedule:1'))
        ->and(SocialPoster::outcome($key)?->platformPostId)->toBe('fb-1')
        ->and(SocialPoster::outcome('schedule:1', 'facebook', credentials())?->platformPostId)->toBe('fb-1')
        ->and(SocialPoster::outcome('schedule:1', 'facebook'))->toBeNull()
        ->and(SocialPoster::outcome($key, Platform::Instagram))->toBeNull()
        ->and(SocialPoster::outcome('legacy-1', 'facebook')?->platformPostId)->toBe('fb-legacy')
        ->and(SocialPoster::status($key))->toBe('published')
        ->and(SocialPoster::status('nothing-here', 'facebook'))->toBeNull()
        ->and(SocialPoster::on('facebook')->using(credentials())->idempotencyKey('schedule:1')->outcome()['facebook']?->platformPostId)->toBe('fb-1');
});

// --- reconcile() --------------------------------------------------------------

it('re-sends PostPublished after a listener failed, without publishing again', function () {
    $log = recordPublished();
    $calls = 0;

    Event::listen(PostPublished::class, function (PostPublished $event) use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('The listener lost its database connection.');
        }
    });

    $failures = new ArrayObject;
    Event::listen(PostFailed::class, fn ($event) => $failures[] = $event);

    $queued = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:2')->post();

    $action = SocialPoster::reconcile($queued->key('facebook'));

    expect($action)->toBe(Poster::RECONCILE_REPLAYED)
        ->and(ReconcileDriver::$calls)->toHaveCount(1)
        ->and($failures)->toHaveCount(0)
        ->and($calls)->toBe(2)
        ->and($log)->toHaveCount(2)
        ->and($log[1]->replayed)->toBeTrue()
        ->and($log[1]->result->platformPostId)->toBe('fb-1')
        ->and($log[1]->idempotencyKey)->toBe($queued->key('facebook'));
});

it('resumes a pending record with the original post', function () {
    $key = keyFor('schedule:3');
    store()->markPending($key, ['phase' => 'poll', 'video_id' => 'v-1']);
    $log = recordPublished();

    $actions = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:3')->reconcile();

    expect($actions)->toBe(['facebook' => Poster::RECONCILE_RESUMED])
        ->and(ReconcileDriver::$calls)->toHaveCount(1)
        ->and(ReconcileDriver::$calls[0]['method'])->toBe('resume')
        ->and(ReconcileDriver::$calls[0]['state']['video_id'])->toBe('v-1')
        ->and(ReconcileDriver::$calls[0]['caption'])->toBe('Hello')
        ->and($log)->toHaveCount(1)
        ->and(store()->completed($key)?->platformPostId)->toBe('fb-1');
});

it('resumes a committing record as recovering, so the driver verifies before creating', function () {
    $key = keyFor('schedule:4');
    store()->markCommitting($key, ['phase' => 'create', '_commits' => true]);
    // The driver verifies first and finds the post live.
    ReconcileDriver::$script = [PublishOutcome::published(PostResult::success(Platform::Facebook, 'fb-found'))];

    $action = SocialPoster::reconcile('schedule:4', 'facebook', credentials(), new SocialPost(caption: 'Hello'));

    expect($action)->toBe(Poster::RECONCILE_RESUMED)
        ->and(ReconcileDriver::$calls[0]['state']['phase'])->toBe('create')
        ->and(ReconcileDriver::$calls[0]['state']['_recovering'])->toBeTrue()
        ->and(store()->completed($key)?->platformPostId)->toBe('fb-found');
});

it('dispatches nothing without the original post, or when nothing is recorded', function () {
    $key = keyFor('schedule:5');
    store()->markPending($key, ['phase' => 'poll']);

    expect(SocialPoster::reconcile($key, 'facebook'))->toBe(Poster::RECONCILE_NEEDS_CONTENT)
        ->and(SocialPoster::reconcile('unknown-key', 'facebook'))->toBe(Poster::RECONCILE_NOTHING)
        ->and(ReconcileDriver::$calls)->toBe([]);

    expect(fn () => SocialPoster::reconcile($key))->toThrow(InvalidArgumentException::class);
});

it('cannot verify a failed record whose state the store does not expose', function () {
    app()->instance(IdempotencyStore::class, new OpaqueStore);
    $key = keyFor('schedule:6');
    store()->markFailed($key, ['phase' => 'poll', '_committed' => true]);

    expect(SocialPoster::reconcile($key, 'facebook', credentials(), new SocialPost(caption: 'Hello')))
        ->toBe(Poster::RECONCILE_UNRECOVERABLE)
        ->and(ReconcileDriver::$calls)->toBe([]);
});

it('reopens a failed record kept by the default cache store, which exposes record()', function () {
    $key = keyFor('schedule:9');
    store()->bindAccount($key, Platform::Facebook, 'page-1');
    store()->markFailed($key, ['phase' => 'poll', '_committed' => true, '_started_at' => 1, '_deadline' => 2]);

    expect(store()->record($key))->toMatchArray(['status' => 'failed', 'platform' => 'facebook', 'account' => 'page-1'])
        ->and(store()->record($key)['state'])->toMatchArray(['phase' => 'poll']);

    $action = SocialPoster::reconcile($key, null, credentials(), new SocialPost(caption: 'Hello'));

    expect($action)->toBe(Poster::RECONCILE_RESUMED)
        ->and(ReconcileDriver::$calls[0]['state']['phase'])->toBe('poll')
        ->and(store()->status($key))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED);
});

it('reopens a failed record with a fresh wait window when the store exposes it', function () {
    $store = new RecordingStore;
    app()->instance(IdempotencyStore::class, $store);
    $key = keyFor('schedule:7');
    $store->bindAccount($key, Platform::Facebook, 'page-1');
    $store->markFailed($key, ['phase' => 'poll', '_committed' => true, '_started_at' => 1, '_deadline' => 2]);

    $action = SocialPoster::reconcile($key, null, credentials(), new SocialPost(caption: 'Hello'));

    expect($action)->toBe(Poster::RECONCILE_RESUMED)
        ->and(ReconcileDriver::$calls[0]['state']['phase'])->toBe('poll')
        ->and(ReconcileDriver::$calls[0]['state']['_started_at'])->toBe(Carbon::now()->getTimestamp())
        ->and($store->status($key))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED);
});

it('keeps the time of the lost create when a failed record is reopened, so the driver searches from it', function () {
    $key = keyFor('schedule:11');
    $failures = new ArrayObject;
    Event::listen(PostFailed::class, fn ($event) => $failures[] = $event);
    $created = Carbon::now()->getTimestamp();

    // The create is sent, then the connection drops: possibly published.
    ReconcileDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'),
    ];

    SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:11')->post();

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->possiblyPublished)->toBeTrue()
        ->and(store()->status($key))->toBe(AtomicIdempotencyStore::STATUS_FAILED);

    // Hours later the app reconciles it on the default (cache) store.
    Carbon::setTestNow(Carbon::now()->addHours(3));

    $actions = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:11')->reconcile();
    $verify = ReconcileDriver::$calls[array_key_last(ReconcileDriver::$calls)];

    expect($actions)->toBe(['facebook' => Poster::RECONCILE_RESUMED])
        ->and($verify['state']['phase'])->toBe('create')
        ->and($verify['state']['_recovering'])->toBeTrue()
        ->and($verify['state']['_committing_since'])->toBe($created);
});

it('refuses to reconcile a failed record without any recorded state', function () {
    $store = new RecordingStore;
    app()->instance(IdempotencyStore::class, $store);
    $key = keyFor('schedule:8');
    $store->markFailed($key);

    expect(SocialPoster::reconcile($key, 'facebook', credentials(), new SocialPost(caption: 'Hello')))
        ->toBe(Poster::RECONCILE_UNRECOVERABLE);
});

// --- Command ------------------------------------------------------------------

it('shows the status and replays a published record from the command', function () {
    $log = recordPublished();
    $queued = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->idempotencyKey('schedule:9')->post();
    $key = (string) $queued->key('facebook');

    $this->artisan('social-poster:reconcile', ['key' => $key, '--status' => true])
        ->expectsOutputToContain('Status: published')
        ->expectsOutputToContain('fb-1')
        ->assertSuccessful();

    expect($log)->toHaveCount(1);

    $this->artisan('social-poster:reconcile', ['key' => $key])
        ->expectsOutputToContain('Replay queued')
        ->assertSuccessful();

    expect($log)->toHaveCount(2)
        ->and($log[1]->replayed)->toBeTrue()
        ->and(ReconcileDriver::$calls)->toHaveCount(1);
});

it('says that a replay from the command carries no metadata or correlation id', function () {
    $log = recordPublished();
    $queued = SocialPoster::on('facebook')->using(credentials())->caption('Hello')->withMetadata(['record' => 99])
        ->idempotencyKey('schedule:12')->post();

    $this->artisan('social-poster:reconcile', ['key' => (string) $queued->key('facebook')])
        ->expectsOutputToContain('Replay queued')
        ->expectsOutputToContain('no metadata and no correlation id: listeners must find their record by $event->idempotencyKey')
        ->assertSuccessful();

    expect($log)->toHaveCount(2)
        ->and($log[0]->result->metadata)->toBe(['record' => 99])
        ->and($log[1]->replayed)->toBeTrue()
        ->and($log[1]->result->metadata)->toBe([])
        ->and($log[1]->correlationId)->toBeNull()
        ->and($log[1]->idempotencyKey)->toBe($queued->key('facebook'));
});

it('reports an unfinished or unknown record from the command without dispatching', function () {
    $key = keyFor('schedule:10');
    store()->markPending($key, ['phase' => 'poll']);

    $this->artisan('social-poster:reconcile', ['key' => $key])
        ->expectsOutputToContain('Status: pending')
        ->expectsOutputToContain('needs the original post')
        ->assertFailed();

    $this->artisan('social-poster:reconcile', ['key' => 'nope'])
        ->expectsOutputToContain('nothing recorded')
        ->assertFailed();

    $this->artisan('social-poster:reconcile', ['key' => 'nope', '--platform' => 'myspace'])
        ->expectsOutputToContain('Unknown platform')
        ->assertFailed();

    expect(ReconcileDriver::$calls)->toBe([]);
});
