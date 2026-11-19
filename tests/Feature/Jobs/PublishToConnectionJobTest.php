<?php

namespace SocialPoster\Tests\Feature\Jobs\PublishJob;

use ArrayObject;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use LogicException;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Events\PostDeduplicated;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\KeyCollisionException;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

// --- Fakes --------------------------------------------------------------------

/**
 * A scripted driver: every publish()/resume() records the call and runs the
 * next scripted step (an outcome, a throwable to throw, or a closure).
 */
class ScriptedDriver extends AbstractPlatform
{
    /** @var list<mixed> */
    public static array $script = [];

    /** Used once the script is exhausted. */
    public static ?Closure $fallback = null;

    /** @var list<array{method: string, state: ?array<string, mixed>, credentials: array<string, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$script = [];
        static::$fallback = null;
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
    protected function step(string $method, PreparedPost $post, ?array $state): mixed
    {
        static::$calls[] = ['method' => $method, 'state' => $state, 'credentials' => $post->credentials->values];

        $step = static::$script !== [] ? array_shift(static::$script) : static::$fallback;

        if ($step instanceof Closure) {
            $step = $step($post, $state);
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        if ($step === null) {
            throw new LogicException('The test script is exhausted.');
        }

        return $step;
    }
}

class CommentingScriptedDriver extends ScriptedDriver implements SupportsFirstComment
{
    public static ?CommentRules $rules = null;

    public static bool $noRules = false;

    public static function reset(): void
    {
        parent::reset();
        static::$rules = null;
        static::$noRules = false;
    }

    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        return static::$noRules ? null : (static::$rules ?? new CommentRules(500));
    }

    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        return new CommentResult('c-1');
    }
}

/** Counts cleanup() calls. */
class CountingTransformer implements MediaTransformer
{
    public int $cleanups = 0;

    public function transform(Media $media, Platform $platform, Capabilities $caps): Media
    {
        return $media;
    }

    public function cleanup(): void
    {
        $this->cleanups++;
    }
}

/** Hands out a new token on every resolve. */
class RotatingResolver implements CredentialsResolver
{
    public static int $calls = 0;

    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        static::$calls++;

        return $dispatched->with(['access_token' => 'tok-'.static::$calls]);
    }
}

/** A database queue whose next matching push fails, like a Redis or SQS blip. */
class FlakyDatabaseQueue extends DatabaseQueue
{
    public static ?Closure $failWhen = null;

    public function push($job, $data = '', $queue = null)
    {
        $this->maybeFail($job);

        return parent::push($job, $data, $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $this->maybeFail($job);

        return parent::later($delay, $job, $data, $queue);
    }

    protected function maybeFail(mixed $job): void
    {
        if (static::$failWhen !== null && (static::$failWhen)($job)) {
            static::$failWhen = null;

            throw new RuntimeException('Connection to the queue backend lost.');
        }
    }
}

/** An idempotency store whose backend is down. */
class DownStore implements AtomicIdempotencyStore
{
    protected function down(): never
    {
        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function completed(string $key): ?PostResult
    {
        $this->down();
    }

    public function markPublished(string $key, PostResult $result): void
    {
        $this->down();
    }

    public function pendingState(string $key): ?array
    {
        $this->down();
    }

    public function markPending(string $key, array $state): void
    {
        $this->down();
    }

    public function forget(string $key): void
    {
        $this->down();
    }

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        $this->down();
    }

    public function release(string $key, string $owner): void
    {
        $this->down();
    }

    public function markCommitting(string $key, array $state): void
    {
        $this->down();
    }

    public function committing(string $key): ?array
    {
        $this->down();
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void
    {
        $this->down();
    }

    public function markFailed(string $key, ?array $state = null): void
    {
        $this->down();
    }

    public function status(string $key): ?string
    {
        $this->down();
    }
}

/** The default store, except that reading a first-comment record fails (a cache blip). */
class CommentRecordDownStore implements AtomicIdempotencyStore
{
    public function __construct(private readonly CacheIdempotencyStore $store) {}

    public function completed(string $key): ?PostResult
    {
        if (str_ends_with($key, ':comment')) {
            throw new RuntimeException('Redis read timed out.');
        }

        return $this->store->completed($key);
    }

    public function markPublished(string $key, PostResult $result): void
    {
        $this->store->markPublished($key, $result);
    }

    public function pendingState(string $key): ?array
    {
        return $this->store->pendingState($key);
    }

    public function markPending(string $key, array $state): void
    {
        $this->store->markPending($key, $state);
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        return $this->store->claim($key, $owner, $leaseSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->store->release($key, $owner);
    }

    public function markCommitting(string $key, array $state): void
    {
        $this->store->markCommitting($key, $state);
    }

    public function committing(string $key): ?array
    {
        return $this->store->committing($key);
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void
    {
        $this->store->bindAccount($key, $platform, $account);
    }

    public function markFailed(string $key, ?array $state = null): void
    {
        $this->store->markFailed($key, $state);
    }

    public function status(string $key): ?string
    {
        return $this->store->status($key);
    }
}

/** The PublishToConnectionJob of v1.0.0, property for property, to record its payloads. */
class V10PublishToConnectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxPolls = 60;

    public int $uniqueFor = 3600;

    /** @param array<string, mixed>|null $state */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly Credentials $credentials,
        public readonly bool $skipValidation = false,
        public readonly ?array $state = null,
        public readonly int $pollCount = 0,
        public readonly ?string $correlationId = null,
    ) {}
}

// --- Helpers ------------------------------------------------------------------

function useDriver(string $class = ScriptedDriver::class): void
{
    app(SocialManager::class)->extend('facebook', fn () => new $class);
}

/** A database queue; 'flaky' makes it a FlakyDatabaseQueue. */
function useDatabaseQueue(string $driver = 'database'): void
{
    if ($driver === 'flaky') {
        app('queue')->extend('flaky', fn () => new class(app('db')) extends DatabaseConnector
        {
            public function connect(array $config)
            {
                return new FlakyDatabaseQueue(
                    $this->connections->connection($config['connection'] ?? null),
                    $config['table'],
                    $config['queue'],
                    $config['retry_after'] ?? 60,
                    $config['after_commit'] ?? null,
                );
            }
        });
    }

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => $driver, 'connection' => null, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90000, 'after_commit' => false,
        ],
    ]);
}

/** Run queued jobs like a worker, travelling in time to delayed ones. Returns how many ran. */
function work(int $max = 40, string $queue = 'default,social'): int
{
    $worker = app('queue.worker');
    $options = new WorkerOptions('default', 0, 512, 0, 0, 0, true);
    $ran = 0;

    while ($ran < $max) {
        $next = DB::table('jobs')->whereNull('reserved_at')->orderBy('available_at')->orderBy('id')->first();

        if ($next === null) {
            break;
        }

        if ($next->available_at > now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp($next->available_at));
        }

        $worker->runNextJob('database', $queue, $options);
        $ran++;
    }

    return $ran;
}

/** The commands waiting in the jobs table. @return list<object> */
function queuedCommands(): array
{
    return DB::table('jobs')->orderBy('id')->get()->map(function ($row) {
        $command = json_decode($row->payload, true)['data']['command'];

        return unserialize($command);
    })->all();
}

function recordEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([PostPublished::class, PostFailed::class, PostRetrying::class, PostProcessing::class, PostDeduplicated::class, CommentSkipped::class, CommentPublished::class, CommentFailed::class, CredentialsRejected::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/** @return list<object> */
function eventsOf(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

/** @param array<string, mixed>|null $state */
function publishJob(?array $state = null, int $poll = 0, ?string $key = 'key-1', ?string $comment = null, array $credentials = ['account_id' => 'acct-1', 'access_token' => 'tok']): PublishToConnectionJob
{
    return new PublishToConnectionJob(
        Platform::Facebook,
        new SocialPost(caption: 'Hello', metadata: ['record' => 7], comment: $comment),
        Credentials::make($credentials),
        true,
        $state,
        $poll,
        'corr-1',
        $key,
    );
}

function published(string $id = 'post-1', ?string $state = null): PublishOutcome
{
    return PublishOutcome::published(PostResult::success(Platform::Facebook, $id, 'https://example.test/'.$id, [], $state));
}

function store(): IdempotencyStore
{
    return app(IdempotencyStore::class);
}

function push(PublishToConnectionJob $job): void
{
    app(BusDispatcher::class)->dispatchToQueue($job);
}

beforeEach(function () {
    ScriptedDriver::reset();
    CommentingScriptedDriver::reset();
    FlakyDatabaseQueue::$failWhen = null;
    RotatingResolver::$calls = 0;
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

// --- Happy path, replay, keys -------------------------------------------------

it('publishes once, records the result and reports the key, attempt and correlation id', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    dispatch(publishJob());
    work();

    expect(ScriptedDriver::$calls)->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty();

    $event = eventsOf($events, PostPublished::class)[0];

    expect($event->result->platformPostId)->toBe('post-1')
        ->and($event->result->metadata)->toBe(['record' => 7])
        ->and($event->idempotencyKey)->toBe('key-1')
        ->and($event->correlationId)->toBe('corr-1')
        ->and($event->attempt)->toBe(1)
        ->and($event->replayed)->toBeFalse()
        ->and(store()->completed('key-1')?->platformPostId)->toBe('post-1');
});

it('replays a published key without calling the driver and never queues a second comment', function () {
    useDriver(CommentingScriptedDriver::class);
    useDatabaseQueue();
    $events = recordEvents();

    store()->markPublished('key-1', PostResult::success(Platform::Facebook, 'post-1'));
    store()->markPublished(AddFirstCommentJob::commentKeyFor('key-1'), PostResult::success(Platform::Facebook, 'c-1'));

    dispatch(publishJob(comment: 'First!'));
    work();

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(eventsOf($events, PostPublished::class)[0]->replayed)->toBeTrue()
        ->and(eventsOf($events, CommentSkipped::class)[0]->reason)->toBe(CommentSkipped::REASON_ALREADY_DONE)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('derives a stable idempotency key per account and never from volatile credentials', function () {
    useDriver();
    $post = new SocialPost(caption: 'Hello');

    $a = PublishToConnectionJob::idempotencyKeyFor(Platform::Facebook, $post, Credentials::make(['account_id' => 'A', 'access_token' => 't1']), 'corr');
    $refreshed = PublishToConnectionJob::idempotencyKeyFor(Platform::Facebook, $post, Credentials::make(['account_id' => 'A', 'access_token' => 't2', 'expires_at' => 99]), 'corr');
    $b = PublishToConnectionJob::idempotencyKeyFor(Platform::Facebook, $post, Credentials::make(['account_id' => 'B', 'access_token' => 't1']), 'corr');

    expect($a)->toBe($refreshed)->not->toBe($b)
        ->and((new PublishToConnectionJob(Platform::Facebook, $post, Credentials::make(['account_id' => 'A', 'access_token' => 't1']), correlationId: 'corr'))->idempotencyKey())->toBe($a);
});

it('keeps the key of a token-only account stable across token refreshes', function (Platform $platform) {
    $post = new SocialPost(caption: 'Hello');

    $first = PublishToConnectionJob::idempotencyKeyFor($platform, $post, Credentials::make(['access_token' => 'tok-1']), 'post:42');
    $refreshed = PublishToConnectionJob::idempotencyKeyFor($platform, $post, Credentials::make(['access_token' => 'tok-2', 'expires_at' => 99]), 'post:42');

    expect($refreshed)->toBe($first)
        ->and(PublishToConnectionJob::idempotencyKeyFor($platform, $post, Credentials::make(['access_token' => 'tok-1']), 'post:43'))->not->toBe($first);
})->with([[Platform::YouTube], [Platform::X], [Platform::TikTok]]);

it('uses a separate, per-step unique id so a continuation never meets the dispatch lock', function () {
    $job = publishJob();
    $next = publishJob(['phase' => 'x'], 1);

    expect($job->uniqueId())->toBe('key-1')
        ->and($next->uniqueId())->toBe('key-1:step:1.0.0');
});

// --- Zone B -------------------------------------------------------------------

it('never re-publishes or reports a failure when a PostPublished listener throws (null store)', function () {
    config(['social.idempotency' => NullIdempotencyStore::class]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    Event::listen(PostPublished::class, fn () => throw new ConnectionException('listener lost its connection'));
    ScriptedDriver::$script = [published()];

    dispatch(publishJob());
    work();

    expect(ScriptedDriver::$calls)->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('keeps the published row and reports nothing when a listener throws on the last attempt of a continuation', function () {
    config(['social.tries' => 2]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    Event::listen(PostPublished::class, fn () => throw new RuntimeException('listener bug'));
    ScriptedDriver::$script = [published()];

    push(publishJob(['phase' => 'await'], 3));
    DB::table('jobs')->update(['attempts' => 1]);
    work();

    expect(ScriptedDriver::$calls)->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(store()->completed('key-1'))->not->toBeNull();
});

// --- Commit protocol ----------------------------------------------------------

it('flags a redelivered commit phase as recovering even with the null store', function () {
    config(['social.idempotency' => NullIdempotencyStore::class]);
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [published()];

    $job = publishJob(['phase' => 'create'], 1);
    $job->commits = true;
    push($job);
    // A worker reserved it and died (or timed out): the queue hands it out again.
    DB::table('jobs')->update(['attempts' => 1]);
    work();

    expect(ScriptedDriver::$calls[0]['state'])
        ->toMatchArray(['phase' => 'create', Pending::RECOVERING => true, Pending::ATTEMPT => 2]);
});

it('runs a commit phase as its own step and verifies after an ambiguous network failure', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'),
        published(),
    ];

    dispatch(publishJob());
    work();

    [$publish, $first, $retry] = ScriptedDriver::$calls;

    expect($publish['method'])->toBe('publish')
        ->and($first['state'])->toMatchArray([Pending::RECOVERING => false, Pending::ATTEMPT => 1])
        ->and($first['state'])->toHaveKey(Pending::COMMITTING_SINCE)
        ->and($retry['state'])->toMatchArray(['phase' => 'create', Pending::RECOVERING => true, Pending::ATTEMPT => 2])
        ->and($retry['state'][Pending::COMMITTING_SINCE])->toBe($first['state'][Pending::COMMITTING_SINCE])
        ->and(eventsOf($events, PostRetrying::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('retries a commit phase without recovering when the request provably never left', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 6: Could not resolve host: graph.facebook.com'),
        published(),
    ];

    dispatch(publishJob());
    work();

    expect(ScriptedDriver::$calls[2]['state'])->toMatchArray([Pending::RECOVERING => false, Pending::ATTEMPT => 2]);
});

it('resumes a commit phase left committing in the store with recovering set', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [published()];

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    $store->markCommitting('key-1', ['phase' => 'create', 'container' => 'c9', '_commits' => true]);

    push(publishJob());
    work();

    expect(ScriptedDriver::$calls[0]['method'])->toBe('resume')
        ->and(ScriptedDriver::$calls[0]['state'])->toMatchArray(['phase' => 'create', 'container' => 'c9', Pending::RECOVERING => true])
        ->and(ScriptedDriver::$calls[0]['state'])->not->toHaveKey('_commits');
});

it('lets only one chain claim a key: the second steps aside without a failure', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    $store->bindAccount('key-1', Platform::Facebook, 'acct-1');
    expect($store->claim('key-1', 'another-chain', 600))->toBeTrue();

    push(publishJob());
    work();

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(eventsOf($events, PostDeduplicated::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty();
});

it('defers a redelivered copy of a step that is still running, then drops it once that run moved the chain on', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 10), published()];
    $job = publishJob();

    // A slow run of this step holds its lock when the queue hands the step out again.
    $running = Cache::lock($job->overlapLockKey(), 600);
    $running->get();
    push($job);
    DB::table('jobs')->update(['attempts' => 1]);
    $start = now()->getTimestamp();
    work(1);

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('available_at') - $start)->toBeGreaterThan($job->timeout)
        ->and(eventsOf($events, PostDeduplicated::class))->toBeEmpty()
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty();

    // The slow run finishes its step and queues the next one.
    $running->release();
    $job->handle(app(SocialManager::class), app('events'), store());
    work();

    // The deferred copy finds the step handed over: no second chain.
    expect(array_column(ScriptedDriver::$calls, 'method'))->toBe(['publish', 'resume'])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostDeduplicated::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('runs a redelivered step once the lock of a worker that died expires, verifying a commit phase', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    $job = publishJob(['phase' => 'create'], 1);
    $job->commits = true;

    // The worker running this step was killed: its finally{} never freed the lock.
    Cache::lock($job->overlapLockKey(), $job->timeout)->get();
    push($job);
    DB::table('jobs')->update(['attempts' => 1]);
    work(1);

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(eventsOf($events, PostDeduplicated::class))->toBeEmpty();

    work();

    expect(ScriptedDriver::$calls)->toHaveCount(1)
        ->and(ScriptedDriver::$calls[0]['state'])->toMatchArray(['phase' => 'create', Pending::RECOVERING => true])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty();
});

it('reports nothing from failed() for a copy of a step another run already handed over', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 10)];
    $job = publishJob();
    $copy = unserialize(serialize($job));

    $job->handle(app(SocialManager::class), app('events'), store());

    // The queue gives up on a redelivered copy of that step.
    $copy->failed(new MaxAttemptsExceededException('PublishToConnectionJob has been attempted too many times.'));

    expect(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(store()->pendingState('key-1'))->toMatchArray(['phase' => 'await'])
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('runs a failed step again when the failed job is retried (queue:retry)', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        new PermanentException('The video could not be fetched.', Platform::Facebook, FailureReason::MediaRejected),
        published(),
    ];
    $job = publishJob();
    $retried = unserialize(serialize($job));

    push($job);
    work();

    expect(eventsOf($events, PostFailed::class))->toHaveCount(1);

    // queue:retry pushes the very same payload again.
    push($retried);
    work();

    expect(ScriptedDriver::$calls)->toHaveCount(2)
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostDeduplicated::class))->toBeEmpty();
});

it('frees the step lock before re-queuing a zero-delay retry, so a fast worker runs the copy', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $job = publishJob();
    $lockKey = $job->overlapLockKey();
    $heldWhenQueued = null;

    DB::listen(function ($query) use (&$heldWhenQueued, $lockKey) {
        if ($heldWhenQueued === null && preg_match('/^insert into [`"]?jobs\b/i', $query->sql) === 1) {
            $probe = Cache::lock($lockKey, 1);
            $heldWhenQueued = ! $probe->get();

            if (! $heldWhenQueued) {
                $probe->release();
            }
        }
    });

    // A 503 with 'Retry-After: 0'.
    ScriptedDriver::$script = [new TemporaryException('HTTP 503', Platform::Facebook, FailureReason::ServerError, 0), published()];

    push($job);
    $heldWhenQueued = null;
    work();

    expect($heldWhenQueued)->toBeFalse()
        ->and(ScriptedDriver::$calls)->toHaveCount(2)
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostDeduplicated::class))->toBeEmpty();
});

it('resumes from the step a committed create recorded when queuing its continuation failed', function () {
    useDriver();
    useDatabaseQueue('flaky');
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        // The create went through: the platform's publish job exists.
        PublishOutcome::pending(['phase' => 'poll', 'publish_id' => 'p-1'], 30, true),
        published(),
    ];
    FlakyDatabaseQueue::$failWhen = fn ($job) => $job instanceof PublishToConnectionJob && ($job->state['phase'] ?? null) === 'poll';

    dispatch(publishJob());
    work();

    $calls = ScriptedDriver::$calls;

    expect($calls)->toHaveCount(3)
        ->and($calls[2]['state'])->toMatchArray(['phase' => 'poll', 'publish_id' => 'p-1', Pending::RECOVERING => false])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(store()->completed('key-1')?->platformPostId)->toBe('post-1');
});

it('reports a key reused for another account as a collision and keeps the first account record', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    $store->bindAccount('key-1', Platform::Facebook, 'acct-other');
    $store->markPublished('key-1', PostResult::success(Platform::Facebook, 'theirs'));

    push(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toBeInstanceOf(KeyCollisionException::class)
        ->and(eventsOf($events, PostPublished::class))->toBeEmpty()
        ->and($store->completed('key-1')?->platformPostId)->toBe('theirs');
});

it('refuses to re-post blind over a record that already ended possibly published', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    $store->markFailed('key-1', ['phase' => 'create']);

    push(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown);
});

// --- Failures -----------------------------------------------------------------

it('reports a driver TypeError exactly once, with the error as previous, and does not retry', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [new \TypeError('publishContainer(): Argument #1 must be of type string, null given')];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(ScriptedDriver::$calls)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception->getPrevious())->toBeInstanceOf(\TypeError::class)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('flags a programming error inside a commit phase as possibly published', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [PublishOutcome::commit(['phase' => 'create']), new \TypeError('null id')];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class)[0];

    expect($failed->possiblyPublished)->toBeTrue()
        ->and($failed->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and(store()->status('key-1'))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('releases a temporary failure with backoff and reports PostFailed once tries run out', function () {
    config(['social.tries' => 2]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$fallback = fn () => new TemporaryException('Graph 500', Platform::Facebook, FailureReason::ServerError);

    dispatch(publishJob());
    $start = now()->getTimestamp();
    work(1);

    $row = DB::table('jobs')->first();

    expect($row->attempts)->toBe(1)
        ->and($row->available_at - $start)->toBe(30)
        ->and(eventsOf($events, PostRetrying::class)[0]->attempt)->toBe(1);

    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(ScriptedDriver::$calls)->toHaveCount(2)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->attempt)->toBe(2)
        ->and($failed[0]->willRetry)->toBeFalse()
        ->and(store()->status('key-1'))->toBeNull();
});

it('never drops a temporary failure silently on the sync queue', function () {
    useDriver();
    $events = recordEvents();
    ScriptedDriver::$script = [new TemporaryException('Graph 500', Platform::Facebook, FailureReason::ServerError)];

    dispatch(publishJob());

    expect(eventsOf($events, PostFailed::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class)[0]->exception->reason)->toBe(FailureReason::ServerError);
});

it('waits between polls on the sync queue instead of burning the budget', function () {
    useDriver();
    $events = recordEvents();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 20), published()];

    dispatch(publishJob());

    Sleep::assertSleptTimes(1);

    expect(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(ScriptedDriver::$calls[1]['state'])->toMatchArray(['phase' => 'await']);
});

it('fails with Expired and never calls the driver once the publish window closed', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();

    $job = new PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Hi'), Credentials::make(['account_id' => 'acct-1', 'access_token' => 't']), true, null, 0, 'corr', 'key-1', now()->subMinute());
    push($job);
    work();

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(eventsOf($events, PostFailed::class)[0]->exception->reason)->toBe(FailureReason::Expired);
});

it('reports an unknown outcome type as a failure', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [new class extends PublishOutcome {}];

    push(publishJob());
    work();

    expect(eventsOf($events, PostFailed::class)[0]->exception->getPrevious())->toBeInstanceOf(LogicException::class);
});

// --- Polling and deadlines ----------------------------------------------------

it('honours the deadline a driver declares on Pending', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $deadline = now()->getTimestamp() + 100;
    ScriptedDriver::$fallback = fn () => PublishOutcome::pending(['phase' => 'await'], 30, false, $deadline);

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Timeout)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(count(ScriptedDriver::$calls))->toBeLessThanOrEqual(5)
        ->and(eventsOf($events, PostProcessing::class))->not->toBeEmpty()
        ->and(store()->status('key-1'))->toBeNull();
});

it('reports a committed post that outlives the wait as possibly published and keeps it for reconcile', function () {
    config(['social.async.max_wait_per_platform.facebook' => 60]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$fallback = fn () => PublishOutcome::pending(['phase' => 'status', 'publish_id' => 'v_1'], 30, true);

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class)[0];

    expect($failed->possiblyPublished)->toBeTrue()
        ->and($failed->exception->reason)->toBe(FailureReason::ProcessingTimeout)
        ->and(store()->status('key-1'))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('carries the clock and the chain flags into resume() through the reserved keys', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await', Pending::RECOVERING => true], 10), published()];

    dispatch(publishJob());
    $started = now()->getTimestamp();
    work();

    expect(ScriptedDriver::$calls[1]['state'])->toMatchArray([
        'phase' => 'await',
        Pending::RECOVERING => false,
        Pending::STARTED_AT => $started,
        Pending::ATTEMPT => 1,
        Pending::RESTARTS => 0,
    ]);
});

it('restarts from scratch when the driver asks to, counting the restart', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [
        new TemporaryException('Blob expired', Platform::Facebook, FailureReason::NotFound, 0, ['restart' => true]),
        PublishOutcome::pending(['phase' => 'await'], 5),
        published(),
    ];

    dispatch(publishJob());
    work();

    expect(array_column(ScriptedDriver::$calls, 'method'))->toBe(['publish', 'publish', 'resume'])
        ->and(ScriptedDriver::$calls[2]['state'][Pending::RESTARTS])->toBe(1);
});

it('keeps continuations on the connection and queue of the chain, past any unique lock', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 10)];

    $job = publishJob()->onQueue('social');
    // Stale unique locks must not drop the chain.
    Cache::lock('laravel_unique_job:'.PublishToConnectionJob::class.':key-1', 3600)->get();
    Cache::lock('laravel_unique_job:'.PublishToConnectionJob::class.':key-1:step:1.0.0', 3600)->get();
    push($job);
    work(1);

    $next = queuedCommands()[0];

    expect(DB::table('jobs')->value('queue'))->toBe('social')
        ->and($next)->toBeInstanceOf(PublishToConnectionJob::class)
        ->and($next->pollCount)->toBe(1)
        ->and($next->idempotencyKey)->toBe('key-1')
        ->and($next->owner)->toBe($job->owner)
        ->and($next->state)->toBe(['phase' => 'await']);
});

// --- Credentials --------------------------------------------------------------

it('reports a rejected token without a resolver as PostFailed plus CredentialsRejected', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [new PermanentException('Session expired', Platform::Facebook, FailureReason::InvalidToken, ['terminal' => false])];

    dispatch(publishJob());
    work();

    $rejected = eventsOf($events, CredentialsRejected::class);

    expect(eventsOf($events, PostFailed::class))->toHaveCount(1)
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->accountKey)->toBe('acct-1')
        ->and($rejected[0]->terminal)->toBeFalse()
        ->and($rejected[0]->reason)->toBe(FailureReason::InvalidToken);
});

it('does not ask for a reconnect when the platform refuses a permission a reconnect cannot change', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [new PermanentException('This privacy level is not available for this creator.', Platform::Facebook, FailureReason::InsufficientPermissions, ['reconnect' => false])];

    dispatch(publishJob());
    work();

    expect(eventsOf($events, PostFailed::class))->toHaveCount(1)
        ->and(eventsOf($events, CredentialsRejected::class))->toBeEmpty();
});

it('retries a rejected token once with re-resolved credentials when a resolver is bound', function () {
    config(['social.credentials_resolver' => RotatingResolver::class]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        new TemporaryException('Token expired', Platform::Facebook, FailureReason::InvalidToken, 0, ['reresolve' => true]),
        published(),
    ];

    dispatch(publishJob());
    work();

    expect(array_column(array_column(ScriptedDriver::$calls, 'credentials'), 'access_token'))->toBe(['tok-1', 'tok-2'])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, CredentialsRejected::class))->toBeEmpty();
});

it('turns a second rejection after re-resolving into a permanent InvalidToken', function () {
    config(['social.credentials_resolver' => RotatingResolver::class]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $rejection = fn () => new TemporaryException('Token rejected', Platform::Facebook, FailureReason::InvalidToken, 0, ['reresolve' => true],
        new PermanentException('Token rejected', Platform::Facebook, FailureReason::InvalidToken, ['http_status' => 401]));
    ScriptedDriver::$script = [$rejection, $rejection];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(ScriptedDriver::$calls)->toHaveCount(2)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toBeInstanceOf(PermanentException::class)
        ->and($failed[0]->exception->context['reresolved'] ?? null)->toBeTrue()
        ->and(eventsOf($events, CredentialsRejected::class))->toHaveCount(1);
});

// --- failed() -----------------------------------------------------------------

it('reports a worker timeout in a commit phase as possibly published and keeps the record', function () {
    useDriver();
    $events = recordEvents();

    $job = publishJob(['phase' => 'create'], 2);
    $job->commits = true;
    $job->failed(new TimeoutExceededException('PublishToConnectionJob has timed out.'));

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->exception->getPrevious())->toBeInstanceOf(TimeoutExceededException::class)
        ->and(store()->status('key-1'))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('reports a worker timeout outside a commit phase as an ordinary timeout', function () {
    useDriver();
    $events = recordEvents();

    publishJob(['phase' => 'await'], 2)->failed(new TimeoutExceededException('timed out'));

    $failed = eventsOf($events, PostFailed::class)[0];

    expect($failed->possiblyPublished)->toBeFalse()
        ->and($failed->exception->reason)->toBe(FailureReason::Timeout);
});

it('still reports PostFailed from failed() when the idempotency store is down', function () {
    useDriver();
    $events = recordEvents();
    app()->instance(IdempotencyStore::class, new DownStore);

    publishJob(['phase' => 'await'], 2)->failed(new TimeoutExceededException('PublishToConnectionJob has timed out.'));

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Timeout);
});

it('reports nothing from failed() for a key that is already published', function () {
    useDriver();
    $events = recordEvents();
    store()->markPublished('key-1', PostResult::success(Platform::Facebook, 'post-1'));

    publishJob()->failed(new TimeoutExceededException('timed out'));

    expect(eventsOf($events, PostFailed::class))->toBeEmpty();
});

// --- Comments -----------------------------------------------------------------

it('queues the first comment with the longer of the configured and the platform delay', function () {
    useDriver(CommentingScriptedDriver::class);
    useDatabaseQueue();
    CommentingScriptedDriver::$rules = new CommentRules(500, null, 120);
    ScriptedDriver::$script = [published()];

    Carbon::setTestNow(now());
    dispatch(publishJob(comment: 'First!'));
    $now = now()->getTimestamp();
    work(1);

    $row = DB::table('jobs')->first();
    $job = queuedCommands()[0];

    expect($job)->toBeInstanceOf(AddFirstCommentJob::class)
        ->and($job->postId)->toBe('post-1')
        ->and($job->idempotencyKey)->toBe('key-1')
        ->and($job->parent?->platformPostId)->toBe('post-1')
        ->and($job->post?->caption)->toBe('Hello')
        ->and($row->available_at - $now)->toBe(120);
});

it('skips the comment, saying why, when the post cannot take one', function (PostResult $result, string $driver, string $reason, bool $noRules = false) {
    useDriver($driver);
    useDatabaseQueue();
    $events = recordEvents();
    CommentingScriptedDriver::$noRules = $noRules;
    ScriptedDriver::$script = [PublishOutcome::published($result)];

    dispatch(publishJob(comment: 'First!'));
    work(1);

    expect(eventsOf($events, CommentSkipped::class)[0]->reason)->toBe($reason)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'scheduled post' => [PostResult::success(Platform::Facebook, 'p', null, [], PostResult::STATE_SCHEDULED), CommentingScriptedDriver::class, CommentSkipped::REASON_SCHEDULED],
    'blank post id' => [PostResult::success(Platform::Facebook, ' '), CommentingScriptedDriver::class, CommentSkipped::REASON_NO_POST_ID],
    'no comment support' => [PostResult::success(Platform::Facebook, 'p'), ScriptedDriver::class, CommentSkipped::REASON_UNSUPPORTED],
    'variant takes none' => [PostResult::success(Platform::Facebook, 'p'), CommentingScriptedDriver::class, CommentSkipped::REASON_STORY, true],
]);

it('queues the first comment of a post that is still processing, after a longer wait', function () {
    useDriver(CommentingScriptedDriver::class);
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published('reel-1', PostResult::STATE_PROCESSING)];

    Carbon::setTestNow(now());
    dispatch(publishJob(comment: 'First!'));
    $now = now()->getTimestamp();
    work(1);

    expect(eventsOf($events, CommentSkipped::class))->toBeEmpty()
        ->and(queuedCommands()[0])->toBeInstanceOf(AddFirstCommentJob::class)
        ->and(DB::table('jobs')->value('available_at') - $now)->toBe(300);

    work();

    expect(eventsOf($events, CommentPublished::class))->toHaveCount(1);
});

it('waits out the platform minimum comment delay on the sync queue, not the configured delay', function () {
    config(['social.comment_delay' => 600]);
    useDriver(CommentingScriptedDriver::class);
    $events = recordEvents();
    // LinkedIn answers 404 to a comment on a post a few seconds old.
    CommentingScriptedDriver::$rules = new CommentRules(500, null, 60);
    ScriptedDriver::$script = [published()];

    dispatch(publishJob(comment: 'First!'));

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 60);

    expect(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, CommentPublished::class))->toHaveCount(1);
});

it('reports the comment failed on the sync queue when the platform minimum exceeds social.sync.max_wait', function () {
    config(['social.sync.max_wait' => 30]);
    useDriver(CommentingScriptedDriver::class);
    $events = recordEvents();
    CommentingScriptedDriver::$rules = new CommentRules(500, null, 60);
    ScriptedDriver::$script = [published()];

    dispatch(publishJob(comment: 'First!'));

    $failed = eventsOf($events, CommentFailed::class);

    Sleep::assertNeverSlept();
    expect(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, CommentPublished::class))->toBeEmpty()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Misconfigured)
        ->and($failed[0]->exception->context)->toMatchArray(['min_delay_seconds' => 60, 'sync_max_wait' => 30]);
});

it('still queues the first comment when the comment record cannot be read', function () {
    app()->instance(IdempotencyStore::class, new CommentRecordDownStore(new CacheIdempotencyStore));
    useDriver(CommentingScriptedDriver::class);
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    dispatch(publishJob(comment: 'First!'));
    work();

    expect(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, CommentPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, CommentFailed::class))->toBeEmpty();
});

it('reports CommentFailed when the first comment cannot be queued', function () {
    app(SocialManager::class)->extend('facebook', fn () => new class extends CommentingScriptedDriver
    {
        public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
        {
            throw new RuntimeException('commentRules() bug');
        }
    });
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    dispatch(publishJob(comment: 'First!'));
    work();

    $failed = eventsOf($events, CommentFailed::class);

    expect(eventsOf($events, PostPublished::class))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->postId)->toBe('post-1')
        ->and($failed[0]->metadata)->toBe(['record' => 7])
        ->and($failed[0]->exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and(DB::table('jobs')->count())->toBe(0);
});

// --- Payloads -----------------------------------------------------------------

it('runs a continuation queued by v1.0, keeping the v1.0 store key', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    $legacy = new V10PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Old'), Credentials::make(['account_id' => 'acct-1', 'access_token' => 't']), true, ['phase' => 'await', 'container_id' => 'c1'], 3, 'corr-v1');
    $command = str_replace(
        'O:'.strlen(V10PublishToConnectionJob::class).':"'.V10PublishToConnectionJob::class.'"',
        'O:'.strlen(PublishToConnectionJob::class).':"'.PublishToConnectionJob::class.'"',
        serialize($legacy),
    );

    $job = unserialize($command);

    expect($job)->toBeInstanceOf(PublishToConnectionJob::class)
        ->and($job->state)->toBe(['phase' => 'await', 'container_id' => 'c1'])
        ->and($job->pollCount)->toBe(3)
        ->and($job->idempotencyKey)->toBeNull()
        ->and($job->owner)->toBeNull()
        ->and($job->commits)->toBeFalse()
        ->and($job->restarts)->toBe(0)
        ->and($job->idempotencyKey())->toBe('corr-v1:facebook');

    // As v1.0 pushed it: an unencrypted command with v1.0's payload fields.
    app('queue')->connection('database')->pushRaw((string) json_encode([
        'uuid' => 'legacy-1',
        'displayName' => PublishToConnectionJob::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'maxTries' => 5,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => '30,120,300,900',
        'timeout' => null,
        'retryUntil' => null,
        'data' => ['commandName' => PublishToConnectionJob::class, 'command' => $command],
    ]));
    work();

    expect(ScriptedDriver::$calls[0]['state'])->toMatchArray(['phase' => 'await', 'container_id' => 'c1'])
        ->and(eventsOf($events, PostPublished::class)[0]->idempotencyKey)->toBe('corr-v1:facebook')
        ->and(store()->completed('corr-v1:facebook'))->not->toBeNull();
});

it('encrypts the payload when the app has a key', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [published()];

    $job = publishJob();
    dispatch($job);

    $command = json_decode((string) DB::table('jobs')->value('payload'), true)['data']['command'];

    expect($job->shouldBeEncrypted)->toBeTrue()
        ->and(str_starts_with($command, 'O:'))->toBeFalse()
        ->and($command)->not->toContain('tok');

    work();

    expect(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('derives the run timeout from the longest upload slice and single call', function () {
    expect(PublishToConnectionJob::configuredTimeout())->toBe(660)
        ->and(PublishToConnectionJob::configuredTimeout(Platform::Pinterest))->toBe(960)
        ->and(publishJob()->timeout)->toBe(660)
        ->and(publishJob()->failOnTimeout)->toBeTrue();

    config(['social.job_timeout' => 1000]);

    expect(PublishToConnectionJob::configuredTimeout(Platform::Pinterest))->toBe(1000);
});

it('cleans up the gateway and transformer temp files once the chain ends, not between steps', function () {
    $transformer = new CountingTransformer;
    app()->instance(MediaTransformer::class, $transformer);
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 10), published()];

    dispatch(publishJob());
    work(1);

    expect($transformer->cleanups)->toBe(0);

    work();

    expect($transformer->cleanups)->toBe(1);
});

// --- Integration round: commit chains, deadlines, reconcile ---------------------

it('runs a create that became ready on the last look at the deadline instead of timing out', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $deadline = now()->getTimestamp() + 60;
    $polls = 0;
    ScriptedDriver::$fallback = function (PreparedPost $post, ?array $state) use (&$polls, $deadline) {
        if (($state['phase'] ?? null) === 'create') {
            return published('video-post');
        }

        // Processing until the run that lands on the deadline, which sees it done.
        return now()->getTimestamp() >= $deadline
            ? PublishOutcome::commit(['phase' => 'create'])
            : PublishOutcome::pending(['phase' => 'await', 'n' => ++$polls], 30, false, $deadline);
    };

    dispatch(publishJob());
    work();

    expect(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(eventsOf($events, PostPublished::class)[0]->result->platformPostId)->toBe('video-post')
        ->and(end(ScriptedDriver::$calls)['state'])->toMatchArray(['phase' => 'create']);
});

it('still times out a create step that keeps asking for another create past the deadline', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $deadline = now()->getTimestamp() + 40;
    ScriptedDriver::$fallback = fn (PreparedPost $post, ?array $state) => $state === null
        ? PublishOutcome::pending(['phase' => 'await'], 30, false, $deadline)
        : PublishOutcome::commit(['phase' => 'verify'], 30, $deadline, true);

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and(store()->status('key-1'))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('times out a create the platform keeps refusing as not published', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $deadline = now()->getTimestamp() + 40;
    // E.g. Instagram's "media not ready": a clear refusal, retried as a create.
    ScriptedDriver::$fallback = fn (PreparedPost $post, ?array $state) => $state === null
        ? PublishOutcome::commit(['phase' => 'publish'], 0, $deadline)
        : PublishOutcome::commit(['phase' => 'publish'], 30, $deadline);

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Timeout)
        ->and(store()->status('key-1'))->toBeNull();
});

it('keeps a verify step after an unclear create possibly published when the publish window closes', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'pin']),
        // The create timed out after sending: the driver hands over to its verify step.
        PublishOutcome::commit(['phase' => 'verify_pin'], 120, null, true),
        published('pin-1'),
    ];

    $job = new PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Hi'), Credentials::make(['account_id' => 'acct-1', 'access_token' => 't']), true, null, 0, 'corr', 'key-1', now()->addSeconds(60));
    push($job);
    work();

    [, $create, $verify] = ScriptedDriver::$calls;

    expect(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(eventsOf($events, PostPublished::class)[0]->result->platformPostId)->toBe('pin-1')
        ->and($verify['state'])->toMatchArray(['phase' => 'verify_pin', Pending::RECOVERING => false])
        ->and($verify['state'][Pending::COMMITTING_SINCE])->toBe($create['state'][Pending::COMMITTING_SINCE]);
});

it('reports an unflagged failure of a verify step after an unclear create as possibly published', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'pin']),
        PublishOutcome::commit(['phase' => 'verify_pin'], 60, null, true),
        new PermanentException('The board is gone.', Platform::Facebook, FailureReason::Misconfigured),
    ];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and(store()->status('key-1'))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('forgets a create retried after a clear refusal when it then fails for good', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'publish']),
        // Throttled: refused, retried as the same create.
        PublishOutcome::commit(['phase' => 'publish'], 60),
        new PermanentException('Daily limit reached.', Platform::Facebook, FailureReason::RateLimited),
    ];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(store()->status('key-1'))->toBeNull();
});

it('does not flag a clean retry chain of a create as possibly published', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    config(['social.tries' => 2]);
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new TemporaryException('Slow down', Platform::Facebook, FailureReason::RateLimited, 5),
        new TemporaryException('Slow down', Platform::Facebook, FailureReason::RateLimited, 5),
    ];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(store()->status('key-1'))->toBeNull();
});

it('refuses to start over from a create that may have gone out', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'),
        new TemporaryException('Blob expired', Platform::Facebook, FailureReason::NotFound, 0, ['restart' => true]),
    ];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect(array_column(ScriptedDriver::$calls, 'method'))->toBe(['publish', 'resume', 'resume'])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->exception->context['restart_refused'])->toBeTrue();
});

it('still restarts a create the driver says cannot exist', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'),
        new TemporaryException('Blob expired', Platform::Facebook, FailureReason::NotFound, 0, ['restart' => true, 'possibly_published' => false]),
        published(),
    ];

    dispatch(publishJob());
    work();

    expect(array_column(ScriptedDriver::$calls, 'method'))->toBe(['publish', 'resume', 'resume', 'publish']);
});

it('keeps the possibly published flag of a token rejection that survived the re-resolve', function () {
    app()->bind(CredentialsResolver::class, RotatingResolver::class);
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    $rejection = fn () => new TemporaryException('Token rejected', Platform::Facebook, FailureReason::InvalidToken, 0, ['reresolve' => true, 'possibly_published' => true], new PermanentException('Token rejected', Platform::Facebook, FailureReason::InvalidToken));
    ScriptedDriver::$script = [$rejection(), $rejection()];

    dispatch(publishJob());
    work();

    $failed = eventsOf($events, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->context['reresolved'])->toBeTrue();
});

it('replays a published record for reconcile even when the credentials name another account, without the comment', function () {
    useDriver(CommentingScriptedDriver::class);
    useDatabaseQueue();
    $events = recordEvents();

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    $store->bindAccount('key-1', Platform::Facebook, 'page-1');
    $store->markPublished('key-1', PostResult::success(Platform::Facebook, 'live-1'));

    $job = new PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Hi', comment: 'First!'), Credentials::ref('conn-9'), true, null, 0, null, 'key-1');
    $job->recovering = true;
    push($job);
    work();

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(eventsOf($events, PostPublished::class)[0]->replayed)->toBeTrue()
        ->and(eventsOf($events, PostPublished::class)[0]->result->platformPostId)->toBe('live-1')
        ->and(eventsOf($events, CommentSkipped::class)[0]->reason)->toBe(CommentSkipped::REASON_ACCOUNT_MISMATCH);
});

it('replays a v1.0 record of a directly dispatched job instead of posting again', function () {
    useDriver();
    useDatabaseQueue();
    $events = recordEvents();
    store()->markPublished('schedule:10:facebook', PostResult::success(Platform::Facebook, 'v1-post'));

    push(new PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Hi'), Credentials::make(['account_id' => 'acct-1', 'access_token' => 't']), true, null, 0, 'schedule:10'));
    work();

    $published = eventsOf($events, PostPublished::class);

    expect(ScriptedDriver::$calls)->toBeEmpty()
        ->and($published)->toHaveCount(1)
        ->and($published[0]->replayed)->toBeTrue()
        ->and($published[0]->result->platformPostId)->toBe('v1-post')
        ->and(store()->completed($published[0]->idempotencyKey)?->platformPostId)->toBe('v1-post');
});

it('keeps the chain lease over a continuation delay so a second dispatch steps aside', function () {
    useDriver();
    useDatabaseQueue();
    ScriptedDriver::$script = [PublishOutcome::pending(['phase' => 'await'], 900)];

    dispatch(publishJob());
    work(1);

    /** @var AtomicIdempotencyStore $store */
    $store = store();
    Carbon::setTestNow(now()->addSeconds(PublishToConnectionJob::configuredTimeout(Platform::Facebook) + 120));

    expect($store->claim('key-1', 'another-owner', 60))->toBeFalse();
});
