<?php

namespace SocialPoster\Tests\Feature\Jobs\CommentJob;

use ArrayObject;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobFailed;
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
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

// --- Fakes --------------------------------------------------------------------

/** A driver on the v2 comment contract whose comment calls follow a script. */
class FirstCommentDriver extends AbstractPlatform implements SupportsFirstComment
{
    public static ?CommentRules $rules = null;

    public static bool $noRules = false;

    /** @var list<mixed> */
    public static array $script = [];

    public static ?Closure $fallback = null;

    /** @var list<array{context: PreparedPost, parent: PostResult, text: string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$rules = null;
        static::$noRules = false;
        static::$script = [];
        static::$fallback = null;
        static::$calls = [];
    }

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(new CaptionRules(0, 3000), new MediaRules(0, 10));
    }

    public function validate(PreparedPost $post): MessageBag
    {
        return new MessageBag;
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        return PublishOutcome::published(PostResult::success(Platform::LinkedIn, 'urn:li:share:1'));
    }

    protected function identityKeys(): array
    {
        return ['author'];
    }

    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        return static::$noRules ? null : (static::$rules ?? new CommentRules(1250));
    }

    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        static::$calls[] = ['context' => $context, 'parent' => $parent, 'text' => $text];

        $step = static::$script !== [] ? array_shift(static::$script) : static::$fallback;

        if ($step instanceof Closure) {
            $step = $step();
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step instanceof CommentResult ? $step : new CommentResult('comment-1', 'https://example.test/c/1', ['raw' => true]);
    }
}

/** A third-party driver that only has the v1.0 comment contract. */
class LegacyCommentDriver extends AbstractPlatform implements SupportsComments
{
    /** @var list<array{postId: string, comment: string, token: mixed}> */
    public static array $calls = [];

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(new CaptionRules(0, 3000), new MediaRules(0, 10));
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        return PublishOutcome::published(PostResult::success(Platform::LinkedIn, 'urn:li:share:1'));
    }

    public function comment(PreparedPost $context, string $postId, string $comment): ?string
    {
        static::$calls[] = ['postId' => $postId, 'comment' => $comment, 'token' => $context->credentials->get('access_token')];

        return 'legacy-comment-1';
    }
}

/** An idempotency store whose backend is down. */
class UnreachableStore implements IdempotencyStore
{
    public function completed(string $key): ?PostResult
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function markPublished(string $key, PostResult $result): void
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function pendingState(string $key): ?array
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function markPending(string $key, array $state): void
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function forget(string $key): void
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }
}

class CountingResolver implements CredentialsResolver
{
    public static int $calls = 0;

    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        static::$calls++;

        return $dispatched->with(['access_token' => 'fresh-'.static::$calls]);
    }
}

/** The AddFirstCommentJob of v1.0.0, property for property. */
class V10AddFirstCommentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly string $comment,
        public readonly Credentials $credentials,
        public readonly array $metadata = [],
    ) {}
}

// --- Helpers ------------------------------------------------------------------

function useCommentDriver(string $class = FirstCommentDriver::class): void
{
    app(SocialManager::class)->extend('linkedin', fn () => new $class);
}

function useCommentQueue(): void
{
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
            'driver' => 'database', 'connection' => null, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90000, 'after_commit' => false,
        ],
    ]);
}

function runQueue(int $max = 40): int
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

        $worker->runNextJob('database', 'default', $options);
        $ran++;
    }

    return $ran;
}

function listenAll(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([CommentPublished::class, CommentFailed::class, CommentSkipped::class, PostPublished::class, PostFailed::class, CredentialsRejected::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/** @return list<object> */
function only(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

function commentJob(string $comment = 'Thanks for reading!', ?PostResult $parent = null, ?string $key = 'key-1'): AddFirstCommentJob
{
    return new AddFirstCommentJob(
        Platform::LinkedIn,
        'urn:li:share:1',
        $comment,
        Credentials::make(['author' => 'urn:li:person:1', 'access_token' => 'tok']),
        ['record' => 9],
        new SocialPost(caption: 'Hello', metadata: ['record' => 9]),
        $parent ?? PostResult::success(Platform::LinkedIn, 'urn:li:share:1', null, ['cid' => 'abc']),
        $key,
        'corr-1',
    );
}

beforeEach(function () {
    FirstCommentDriver::reset();
    LegacyCommentDriver::$calls = [];
    CountingResolver::$calls = 0;
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

// --- Happy paths --------------------------------------------------------------

it('adds the comment through the v2 contract, records it and reports url and payload', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();

    dispatch(commentJob());
    runQueue();

    $published = only($events, CommentPublished::class);

    expect(FirstCommentDriver::$calls)->toHaveCount(1)
        ->and(FirstCommentDriver::$calls[0]['text'])->toBe('Thanks for reading!')
        ->and(FirstCommentDriver::$calls[0]['parent']->payload)->toBe(['cid' => 'abc'])
        ->and(FirstCommentDriver::$calls[0]['context']->caption())->toBe('Hello')
        ->and($published)->toHaveCount(1)
        ->and($published[0]->commentId)->toBe('comment-1')
        ->and($published[0]->url)->toBe('https://example.test/c/1')
        ->and($published[0]->payload)->toBe(['raw' => true])
        ->and($published[0]->metadata)->toBe(['record' => 9])
        ->and(app(IdempotencyStore::class)->completed('key-1:comment')?->platformPostId)->toBe('comment-1');
});

it('never comments twice: a recorded comment is skipped as already done', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    app(IdempotencyStore::class)->markPublished('key-1:comment', PostResult::success(Platform::LinkedIn, 'comment-1'));

    dispatch(commentJob());
    runQueue();

    expect(FirstCommentDriver::$calls)->toBeEmpty()
        ->and(only($events, CommentSkipped::class)[0]->reason)->toBe(CommentSkipped::REASON_ALREADY_DONE);
});

it('is unique per comment key while queued', function () {
    useCommentDriver();
    useCommentQueue();

    dispatch(commentJob());
    dispatch(commentJob());

    expect(commentJob()->uniqueId())->toBe('key-1:comment')
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('still accepts the v1.0 constructor shape and calls a legacy SupportsComments driver', function () {
    useCommentDriver(LegacyCommentDriver::class);
    useCommentQueue();
    $events = listenAll();

    dispatch(new AddFirstCommentJob(Platform::LinkedIn, 'urn:li:share:7', 'Legacy!', Credentials::make(['access_token' => 'tok']), ['id' => 1]));
    runQueue();

    expect(LegacyCommentDriver::$calls)->toBe([['postId' => 'urn:li:share:7', 'comment' => 'Legacy!', 'token' => 'tok']])
        ->and(only($events, CommentPublished::class)[0]->commentId)->toBe('legacy-comment-1');
});

it('runs a comment job queued by v1.0', function () {
    useCommentDriver(LegacyCommentDriver::class);
    useCommentQueue();
    $events = listenAll();

    $command = str_replace(
        'O:'.strlen(V10AddFirstCommentJob::class).':"'.V10AddFirstCommentJob::class.'"',
        'O:'.strlen(AddFirstCommentJob::class).':"'.AddFirstCommentJob::class.'"',
        serialize(new V10AddFirstCommentJob(Platform::LinkedIn, 'urn:li:share:3', 'Old comment', Credentials::make(['access_token' => 'tok']), ['id' => 3])),
    );

    $job = unserialize($command);

    expect($job)->toBeInstanceOf(AddFirstCommentJob::class)
        ->and($job->post)->toBeNull()
        ->and($job->parent)->toBeNull()
        ->and($job->idempotencyKey)->toBeNull()
        ->and($job->commentKey())->toBe('social-poster:comment:linkedin:urn:li:share:3');

    app('queue')->connection('database')->pushRaw((string) json_encode([
        'uuid' => 'legacy-comment',
        'displayName' => AddFirstCommentJob::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'maxTries' => 3,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => null,
        'retryUntil' => null,
        'data' => ['commandName' => AddFirstCommentJob::class, 'command' => $command],
    ]));
    runQueue();

    expect(LegacyCommentDriver::$calls[0]['postId'])->toBe('urn:li:share:3')
        ->and(only($events, CommentPublished::class))->toHaveCount(1);
});

// --- Skips --------------------------------------------------------------------

it('skips without calling the platform, saying why', function (Closure $arrange, string $reason) {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $job = $arrange();

    dispatch($job);
    runQueue();

    expect(FirstCommentDriver::$calls)->toBeEmpty()
        ->and(only($events, CommentSkipped::class)[0]->reason)->toBe($reason)
        ->and(only($events, CommentFailed::class))->toBeEmpty();
})->with([
    'blank text' => [fn () => commentJob('   '), CommentSkipped::REASON_EMPTY],
    'scheduled parent' => [fn () => commentJob(parent: PostResult::success(Platform::LinkedIn, 'urn:li:share:1', null, [], PostResult::STATE_SCHEDULED)), CommentSkipped::REASON_SCHEDULED],
    'variant takes none' => [function () {
        FirstCommentDriver::$noRules = true;

        return commentJob();
    }, CommentSkipped::REASON_STORY],
    'known missing scope' => [function () {
        FirstCommentDriver::$rules = new CommentRules(1250, null, 0, [['w_member_social']]);

        return new AddFirstCommentJob(Platform::LinkedIn, 'urn:li:share:1', 'Hi', Credentials::make(['access_token' => 't', 'scopes' => 'openid profile']), [], null, null, 'key-2');
    }, CommentSkipped::REASON_MISSING_SCOPE],
]);

it('skips a platform without comment support', function () {
    app(SocialManager::class)->extend('linkedin', fn () => new class extends AbstractPlatform
    {
        public function platform(): Platform
        {
            return Platform::LinkedIn;
        }

        public function capabilities(): Capabilities
        {
            return new Capabilities(new CaptionRules, new MediaRules);
        }

        public function publish(PreparedPost $post): PublishOutcome
        {
            return PublishOutcome::published(PostResult::success(Platform::LinkedIn, 'x'));
        }
    });
    $events = listenAll();

    commentJob()->handle(app(SocialManager::class), app('events'));

    expect(only($events, CommentSkipped::class)[0]->reason)->toBe(CommentSkipped::REASON_UNSUPPORTED);
});

it('reports a comment over the platform limit as a failure without calling the platform', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$rules = new CommentRules(10);

    dispatch(commentJob('This comment is far too long'));
    runQueue();

    $failed = only($events, CommentFailed::class);

    expect(FirstCommentDriver::$calls)->toBeEmpty()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::InvalidContent);
});

// --- Retries and failures -----------------------------------------------------

it('retries network errors with backoff and reports CommentFailed exactly once at the end', function () {
    config(['social.comment_tries' => 3]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$fallback = fn () => new ConnectionException('cURL error 28: Operation timed out');

    dispatch(commentJob());
    $start = now()->getTimestamp();
    runQueue(1);

    expect(DB::table('jobs')->value('available_at') - $start)->toBe(60);

    runQueue();

    $failed = only($events, CommentFailed::class);

    expect(FirstCommentDriver::$calls)->toHaveCount(3)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->attempt)->toBe(3)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Network)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('honours Retry-After, capped at 600 seconds', function () {
    useCommentDriver();
    useCommentQueue();
    FirstCommentDriver::$script = [new TemporaryException('Slow down', Platform::LinkedIn, FailureReason::RateLimited, 7200)];

    dispatch(commentJob());
    $start = now()->getTimestamp();
    runQueue(1);

    expect(DB::table('jobs')->value('available_at') - $start)->toBe(600);
});

it('treats an unexpected throwable as temporary', function () {
    config(['social.comment_tries' => 2]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [new \RuntimeException('socket closed')];

    dispatch(commentJob());
    runQueue();

    expect(FirstCommentDriver::$calls)->toHaveCount(2)
        ->and(only($events, CommentPublished::class))->toHaveCount(1)
        ->and(only($events, CommentFailed::class))->toBeEmpty();
});

it('reports a permanent error once and deletes the job', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [new PermanentException('Comments are off', Platform::LinkedIn, FailureReason::InsufficientPermissions)];

    dispatch(commentJob());
    runQueue();

    expect(only($events, CommentFailed::class))->toHaveCount(1)
        ->and(FirstCommentDriver::$calls)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('fails the job in the queue with comment_fail_jobs and still reports once', function () {
    config(['social.comment_fail_jobs' => true]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $failedJobs = new ArrayObject;
    Event::listen(JobFailed::class, function ($event) use ($failedJobs) {
        $failedJobs[] = $event;
    });
    FirstCommentDriver::$script = [new PermanentException('Comments are off', Platform::LinkedIn, FailureReason::InsufficientPermissions)];

    dispatch(commentJob());
    runQueue();

    expect(only($events, CommentFailed::class))->toHaveCount(1)
        ->and($failedJobs)->toHaveCount(1);
});

it('re-resolves credentials once on a rejected token, then gives up for good', function () {
    config(['social.credentials_resolver' => CountingResolver::class]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $rejected = fn () => new TemporaryException('Token rejected', Platform::LinkedIn, FailureReason::InvalidToken, 0, ['reresolve' => true]);
    FirstCommentDriver::$script = [$rejected, new CommentResult('comment-9')];

    dispatch(commentJob());
    runQueue();

    expect(array_map(fn ($call) => $call['context']->credentials->get('access_token'), FirstCommentDriver::$calls))->toBe(['fresh-1', 'fresh-2'])
        ->and(only($events, CommentPublished::class)[0]->commentId)->toBe('comment-9');

    FirstCommentDriver::reset();
    FirstCommentDriver::$script = [$rejected, $rejected];

    dispatch(commentJob(key: 'key-2'));
    runQueue();

    $failed = only($events, CommentFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toBeInstanceOf(PermanentException::class)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::InvalidToken);
});

it('reports a worker failure from failed() unless the comment is recorded', function () {
    $events = listenAll();

    commentJob()->failed(new MaxAttemptsExceededException('too many attempts'));

    expect(only($events, CommentFailed::class))->toHaveCount(1)
        ->and(only($events, CommentFailed::class)[0]->exception->getPrevious())->toBeInstanceOf(MaxAttemptsExceededException::class);

    app(IdempotencyStore::class)->markPublished('key-1:comment', PostResult::success(Platform::LinkedIn, 'comment-1'));
    commentJob()->failed(new MaxAttemptsExceededException('too many attempts'));

    expect(only($events, CommentFailed::class))->toHaveCount(1);
});

it('still reports CommentFailed from failed() when the store cannot be read', function () {
    $events = listenAll();
    app()->instance(IdempotencyStore::class, new UnreachableStore);

    commentJob()->failed(new MaxAttemptsExceededException('too many attempts'));

    expect(only($events, CommentFailed::class))->toHaveCount(1);
});

it('flags the connection with CredentialsRejected when a comment is refused for a token or scope reason', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [new PermanentException(
        'Not enough permissions to comment.',
        Platform::LinkedIn,
        FailureReason::InsufficientPermissions,
        ['http_status' => 403],
    )];

    dispatch(commentJob());
    runQueue();

    $rejected = only($events, CredentialsRejected::class);

    expect(only($events, CommentFailed::class))->toHaveCount(1)
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->platform)->toBe(Platform::LinkedIn)
        ->and($rejected[0]->accountKey)->toBe('urn:li:person:1')
        ->and($rejected[0]->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($rejected[0]->metadata)->toBe(['record' => 9])
        ->and($rejected[0]->errorSummary())->not->toBeEmpty();

    // Reported from failed() too (social.comment_fail_jobs, or the worker's own failure path).
    commentJob(key: 'key-2')->failed(new PermanentException('Token revoked', Platform::LinkedIn, FailureReason::InvalidToken, ['terminal' => true]));

    $rejected = only($events, CredentialsRejected::class);

    expect($rejected)->toHaveCount(2)
        ->and($rejected[1]->reason)->toBe(FailureReason::InvalidToken)
        ->and($rejected[1]->terminal)->toBeTrue();

    // A refusal that a reconnect would not fix sends no CredentialsRejected.
    commentJob(key: 'key-3')->failed(new PermanentException('Comments are closed', Platform::LinkedIn, FailureReason::InvalidContent));

    expect(only($events, CredentialsRejected::class))->toHaveCount(2);
});

it('adds the comment to a parent that was still processing when the publish returned', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();

    dispatch(commentJob(parent: PostResult::success(Platform::LinkedIn, 'urn:li:share:1', null, [], PostResult::STATE_PROCESSING)));
    runQueue();

    expect(FirstCommentDriver::$calls)->toHaveCount(1)
        ->and(only($events, CommentSkipped::class))->toBeEmpty()
        ->and(only($events, CommentPublished::class))->toHaveCount(1);
});

it('lets one run add a comment at a time: a redelivered copy waits for the lock, then finds it done', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $job = commentJob();

    // A slow first run (or one whose worker died) holds the comment's run lock.
    $running = Cache::lock($job->runLockKey(), 120);
    $running->get();
    dispatch($job);
    DB::table('jobs')->update(['attempts' => 1]);
    $start = now()->getTimestamp();
    runQueue(1);

    expect(FirstCommentDriver::$calls)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('available_at') - $start)->toBeGreaterThan(120)
        ->and(only($events, CommentFailed::class))->toBeEmpty();

    // That run adds the comment and records it.
    app(IdempotencyStore::class)->markPublished('key-1:comment', PostResult::success(Platform::LinkedIn, 'comment-1'));
    $running->release();
    runQueue();

    expect(FirstCommentDriver::$calls)->toBeEmpty()
        ->and(only($events, CommentSkipped::class)[0]->reason)->toBe(CommentSkipped::REASON_ALREADY_DONE)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('frees the run lock before re-queuing a zero-delay retry', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $job = commentJob();
    $lockKey = $job->runLockKey();
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

    FirstCommentDriver::$script = [new TemporaryException('HTTP 503', Platform::LinkedIn, FailureReason::ServerError, 0)];

    dispatch($job);
    $heldWhenQueued = null;
    runQueue();

    expect($heldWhenQueued)->toBeFalse()
        ->and(FirstCommentDriver::$calls)->toHaveCount(2)
        ->and(only($events, CommentPublished::class))->toHaveCount(1);
});

it('keeps the post a success when the comment keeps failing on the network', function () {
    config(['social.comment_tries' => 2, 'social.comment_delay' => 0]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$fallback = fn () => new ConnectionException('cURL error 7: Failed to connect');

    dispatch(new PublishToConnectionJob(
        Platform::LinkedIn,
        new SocialPost(caption: 'Hello', comment: 'First!'),
        Credentials::make(['author' => 'urn:li:person:1', 'access_token' => 'tok']),
        true,
        null,
        0,
        'corr-2',
        'key-3',
    ));
    runQueue();

    expect(only($events, PostPublished::class))->toHaveCount(1)
        ->and(only($events, PostFailed::class))->toBeEmpty()
        ->and(only($events, CommentFailed::class))->toHaveCount(1)
        ->and(FirstCommentDriver::$calls)->toHaveCount(2);
});

// --- Ambiguous attempts ---------------------------------------------------------

it('takes a duplicate refusal after an unanswered attempt as that attempt\'s comment', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [
        new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'),
        new PermanentException('You are not allowed to create a Tweet with duplicate content.', Platform::LinkedIn, FailureReason::DuplicateContent),
    ];

    dispatch(commentJob());
    runQueue();

    $published = only($events, CommentPublished::class);

    expect(FirstCommentDriver::$calls)->toHaveCount(2)
        ->and(only($events, CommentFailed::class))->toBeEmpty()
        ->and($published)->toHaveCount(1)
        ->and($published[0]->commentId)->toBeNull()
        ->and($published[0]->payload)->toMatchArray(['recovered' => true, 'duplicate_refused' => true])
        ->and(app(IdempotencyStore::class)->completed('key-1:comment'))->not->toBeNull();
});

it('still reports a duplicate refusal of a comment that was never sent before as CommentFailed', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [
        new ConnectionException('cURL error 6: Could not resolve host: api.linkedin.com'),
        new PermanentException('Duplicate content.', Platform::LinkedIn, FailureReason::DuplicateContent),
    ];

    dispatch(commentJob());
    runQueue();

    expect(only($events, CommentPublished::class))->toBeEmpty()
        ->and(only($events, CommentFailed::class)[0]->exception->reason)->toBe(FailureReason::DuplicateContent);
});

it('still reports a duplicate refusal on the first attempt as CommentFailed', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [new PermanentException('Duplicate content.', Platform::LinkedIn, FailureReason::DuplicateContent)];

    dispatch(commentJob());
    runQueue();

    expect(only($events, CommentPublished::class))->toBeEmpty()
        ->and(only($events, CommentFailed::class))->toHaveCount(1)
        ->and(only($events, CommentFailed::class)[0]->exception->reason)->toBe(FailureReason::DuplicateContent)
        ->and(only($events, CommentFailed::class)[0]->exception->possiblyPublished())->toBeFalse();
});

it('tells the driver through the parent payload when an earlier attempt may have added the comment', function (Throwable $lost) {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = [$lost];

    dispatch(commentJob());
    runQueue();

    expect(FirstCommentDriver::$calls)->toHaveCount(2)
        ->and(FirstCommentDriver::$calls[0]['parent']->payload)->toBe(['cid' => 'abc'])
        ->and(FirstCommentDriver::$calls[1]['parent']->payload)->toBe(['cid' => 'abc', Pending::RECOVERING => true])
        ->and(Pending::isRecovering(FirstCommentDriver::$calls[1]['parent']->payload))->toBeTrue()
        ->and(FirstCommentDriver::$calls[1]['parent']->platformPostId)->toBe('urn:li:share:1')
        ->and(FirstCommentDriver::$calls[1]['parent']->isPublished())->toBeTrue()
        ->and(only($events, CommentPublished::class))->toHaveCount(1);
})->with([
    'connection lost after sending' => [new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')],
    '5xx' => [new TemporaryException('HTTP 503', Platform::LinkedIn, FailureReason::ServerError, 0, ['http_status' => 503])],
    'unexpected error' => [new \RuntimeException('socket closed')],
]);

it('does not flag the retry of an attempt that provably added nothing', function (Throwable $clean) {
    useCommentDriver();
    useCommentQueue();
    FirstCommentDriver::$script = [$clean];

    dispatch(commentJob());
    runQueue();

    expect(FirstCommentDriver::$calls)->toHaveCount(2)
        ->and(FirstCommentDriver::$calls[1]['parent']->payload)->toBe(['cid' => 'abc']);
})->with([
    'request never sent' => [new ConnectionException('cURL error 7: Failed to connect to api.linkedin.com port 443')],
    'throttled' => [new TemporaryException('Slow down', Platform::LinkedIn, FailureReason::RateLimited, 0, ['http_status' => 429])],
    '4xx not live yet' => [new TemporaryException('Not found yet', Platform::LinkedIn, FailureReason::NotFound, 0, ['http_status' => 404])],
    'driver verified nothing was posted' => [new TemporaryException('HTTP 500', Platform::LinkedIn, FailureReason::ServerError, 0, ['http_status' => 500, 'possibly_published' => false])],
]);

it('records that a call is in flight before making it, so the redelivery after a dead worker is a recovery', function () {
    useCommentDriver();
    useCommentQueue();
    $job = commentJob();
    $markedDuringCall = null;
    FirstCommentDriver::$script = [function () use ($job, &$markedDuringCall) {
        $markedDuringCall = Cache::has($job->sentMarkerKey());

        return new CommentResult('comment-1');
    }];

    dispatch($job);
    runQueue();

    // A clean success forgets the marker again.
    expect($markedDuringCall)->toBeTrue()
        ->and(Cache::has($job->sentMarkerKey()))->toBeFalse()
        ->and(FirstCommentDriver::$calls[0]['parent']->payload)->toBe(['cid' => 'abc']);

    // A worker killed (timeout, deploy) during the call never reaches its
    // cleanup: the marker stays and the queue redelivers the job.
    FirstCommentDriver::reset();
    $killed = commentJob(key: 'key-2');
    Cache::put($killed->sentMarkerKey(), 1, 3600);

    dispatch($killed);
    runQueue();

    expect(FirstCommentDriver::$calls)->toHaveCount(1)
        ->and(Pending::isRecovering(FirstCommentDriver::$calls[0]['parent']->payload))->toBeTrue()
        ->and(Cache::has($killed->sentMarkerKey()))->toBeFalse();
});

it('reads a duplicate refusal after a dead worker as that run\'s comment', function () {
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    $job = commentJob();
    Cache::put($job->sentMarkerKey(), 1, 3600);
    FirstCommentDriver::$script = [new PermanentException('Duplicate content.', Platform::LinkedIn, FailureReason::DuplicateContent)];

    dispatch($job);
    runQueue();

    expect(only($events, CommentFailed::class))->toBeEmpty()
        ->and(only($events, CommentPublished::class)[0]->payload)->toMatchArray(['recovered' => true, 'duplicate_refused' => true]);
});

it('reports the comment as possibly published when its last attempt, or an earlier one, lost its answer', function (array $script, bool $possibly) {
    config(['social.comment_tries' => 2]);
    useCommentDriver();
    useCommentQueue();
    $events = listenAll();
    FirstCommentDriver::$script = $script;

    dispatch(commentJob());
    runQueue();

    $failed = only($events, CommentFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->possiblyPublished())->toBe($possibly);
})->with([
    'last answer lost' => [[
        new ConnectionException('cURL error 7: Failed to connect'),
        new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'),
    ], true],
    'refused after an unanswered attempt' => [[
        new TemporaryException('HTTP 502', Platform::LinkedIn, FailureReason::ServerError, 0, ['http_status' => 502]),
        new PermanentException('Not enough permissions to comment.', Platform::LinkedIn, FailureReason::InsufficientPermissions, ['http_status' => 403]),
    ], true],
    'never sent' => [[
        new ConnectionException('cURL error 7: Failed to connect'),
        new ConnectionException('cURL error 6: Could not resolve host: api.linkedin.com'),
    ], false],
    'refused cleanly' => [[
        new TemporaryException('Slow down', Platform::LinkedIn, FailureReason::RateLimited, 0, ['http_status' => 429]),
        new PermanentException('Comments are off', Platform::LinkedIn, FailureReason::InsufficientPermissions, ['http_status' => 403]),
    ], false],
]);

it('reports a worker that gave up during the platform call as possibly published', function () {
    $events = listenAll();
    $job = commentJob();
    Cache::put($job->sentMarkerKey(), 1, 3600);

    $job->failed(new TimeoutExceededException('AddFirstCommentJob has timed out.'));

    $failed = only($events, CommentFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->exception->possiblyPublished())->toBeTrue();

    // A timeout outside the platform call added nothing.
    commentJob(key: 'key-2')->failed(new TimeoutExceededException('AddFirstCommentJob has timed out.'));

    $failed = only($events, CommentFailed::class);

    expect($failed)->toHaveCount(2)
        ->and($failed[1]->exception->reason)->toBe(FailureReason::Timeout)
        ->and($failed[1]->exception->possiblyPublished())->toBeFalse();
});

// --- Run timeout ------------------------------------------------------------------

it('derives its timeout from the platform\'s publish and HTTP timeouts', function () {
    useCommentQueue();
    config([
        'social.http_timeout' => 60,
        'social.publish_timeout' => 120,
        'social.api.threads.connect_timeout' => 10,
        'social.api.threads.reply_status_polls' => 5,
        'social.api.threads.reply_status_interval' => 2,
    ]);

    $threads = new AddFirstCommentJob(Platform::Threads, '1789', 'Hi', Credentials::make(['account_id' => '1', 'access_token' => 'tok']));

    // threads_publish (120) + create, status reads and permalink (3 x (60 + 10)) + 5 x 2 s of waits + 60.
    expect($threads->timeout)->toBe(400)
        ->and(AddFirstCommentJob::configuredTimeout(Platform::Threads))->toBe(400)
        ->and($threads->uniqueFor)->toBeGreaterThanOrEqual($threads->timeout + 60)
        ->and($threads->failOnTimeout)->toBeFalse();

    dispatch($threads);

    expect(json_decode((string) DB::table('jobs')->value('payload'), true)['timeout'])->toBe(400);

    config(['social.api.threads.publish_timeout' => 300, 'social.api.threads.timeout' => 90]);

    expect(AddFirstCommentJob::configuredTimeout('threads'))->toBe(300 + 3 * (90 + 10) + 10 + 60);

    config(['social.comment_timeout' => 900]);

    expect((new AddFirstCommentJob(Platform::Threads, '1789', 'Hi', Credentials::make(['access_token' => 'tok'])))->timeout)->toBe(900);
});
