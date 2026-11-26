<?php

namespace SocialPoster\Tests\Feature\PostBuilderTest;

use ArrayObject;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Bus\Dispatcher as LaravelBusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Events\PostDeduplicated;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostQueued;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\UnsupportedPlatformException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\PostBuilder;
use SocialPoster\Poster;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\QueuedPost;
use SocialPoster\ValueObjects\RawOptions;
use SocialPoster\ValueObjects\SocialPost;
use SocialPoster\ValueObjects\ValidationResult;
use Throwable;
use TypeError;

// --- Fakes --------------------------------------------------------------------

/**
 * A driver for any platform whose publish()/resume() run a per-platform
 * script (outcomes, throwables or closures); unscripted calls publish.
 */
class BuilderDriver extends AbstractPlatform
{
    /** @var array<string, list<mixed>> */
    public static array $scripts = [];

    /** @var list<array{platform: string, method: string, state: ?array<string, mixed>, credentials: array<string, mixed>, options: mixed}> */
    public static array $calls = [];

    /** @var array<string, array<string, list<string>>> */
    public static array $errors = [];

    public static ?Closure $validator = null;

    public function __construct(protected Platform $case)
    {
        parent::__construct();
    }

    public static function reset(): void
    {
        static::$scripts = [];
        static::$calls = [];
        static::$errors = [];
        static::$validator = null;
    }

    public function platform(): Platform
    {
        return $this->case;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(new CaptionRules(0, 5000), new MediaRules(0, 10));
    }

    public function validate(PreparedPost $post): MessageBag
    {
        if (static::$validator !== null) {
            return (static::$validator)($post);
        }

        return new MessageBag(static::$errors[$this->case->value] ?? []);
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        return $this->step('publish', $post, null);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->step('resume', $post, $state);
    }

    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        if ($feature !== Feature::Publish) {
            return [];
        }

        return $credentials->get('login') === 'business'
            ? [['scope.'.$this->case->value], ['business']]
            : [['scope.'.$this->case->value]];
    }

    /** @param array<string, mixed>|null $state */
    protected function step(string $method, PreparedPost $post, ?array $state): PublishOutcome
    {
        $value = $this->case->value;

        static::$calls[] = [
            'platform' => $value,
            'method' => $method,
            'state' => $state,
            'credentials' => $post->credentials->values,
            'options' => $post->options(),
        ];

        $step = (static::$scripts[$value] ?? []) !== [] ? array_shift(static::$scripts[$value]) : published($this->case);

        if ($step instanceof Closure) {
            $step = $step($post, $state);
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    }
}

/** Reports state['container_id'] as the platform id it already gave. */
class IdentifyingBuilderDriver extends BuilderDriver
{
    public function identifiersOf(array $state): array
    {
        return is_string($state['container_id'] ?? null) ? ['container_id' => $state['container_id']] : [];
    }
}

/** A driver whose account identity is a user_id credential, as on X. */
class UserIdDriver extends BuilderDriver
{
    protected function identityKeys(): array
    {
        return ['user_id'];
    }
}

class CommentingDriver extends BuilderDriver implements SupportsFirstComment
{
    public static ?CommentRules $rules = null;

    public static mixed $commentStep = null;

    /** @var list<array{0: ?string, 1: string}> */
    public static array $comments = [];

    /** @var list<int> Unix time of each comment. */
    public static array $commentedAt = [];

    public static function reset(): void
    {
        parent::reset();
        static::$rules = null;
        static::$commentStep = null;
        static::$comments = [];
        static::$commentedAt = [];
    }

    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        return static::$rules ?? new CommentRules(500);
    }

    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        static::$comments[] = [$parent->platformPostId, $text];
        static::$commentedAt[] = Carbon::now()->getTimestamp();

        if (static::$commentStep instanceof Throwable) {
            throw static::$commentStep;
        }

        return new CommentResult('comment-1');
    }
}

class ThrowingBus extends LaravelBusDispatcher
{
    public function dispatchToQueue($command)
    {
        throw new RuntimeException('The queue is down.');
    }
}

/** Picks the account from the metadata at run time; nothing is dispatched. */
class MetadataResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return Credentials::make(['account_id' => 'acct-'.$metadata['account'], 'access_token' => 'tok-'.$metadata['account']]);
    }
}

/** A builder on a framework whose transactions cannot release a lock on rollback (early Laravel 12). */
class NoRollbackHookBuilder extends PostBuilder
{
    protected function rollbackReleasesUniqueLocks(object $transactions): bool
    {
        return false;
    }
}

/** Not a SocialPlatform: a typo'd class in config('social.drivers'). */
class NotADriver {}

// --- Helpers ------------------------------------------------------------------

function useDrivers(string $class = BuilderDriver::class, Platform ...$platforms): void
{
    foreach ($platforms ?: [Platform::Facebook, Platform::Instagram, Platform::LinkedIn] as $platform) {
        app(SocialManager::class)->extend($platform->value, fn () => new $class($platform));
    }
}

function published(Platform $platform, ?string $id = null, ?string $state = null): PublishOutcome
{
    return PublishOutcome::published(PostResult::success($platform, $id ?? $platform->value.'-post', null, [], $state));
}

function recordEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([
        PostQueued::class, PostPublished::class, PostFailed::class, PostDeduplicated::class, PostRetrying::class,
        PostProcessing::class, CommentPublished::class, CommentFailed::class, CommentSkipped::class,
    ] as $class) {
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

/** @return array<string, array<string, string>> */
function creds(): array
{
    return [
        'facebook' => ['account_id' => 'page-1', 'page_access_token' => 'fb-token'],
        'instagram' => ['account_id' => 'ig-1', 'access_token' => 'ig-token'],
        'linkedin' => ['author' => 'urn:li:person:1', 'access_token' => 'li-token'],
    ];
}

function callsFor(string $platform): array
{
    return array_values(array_filter(BuilderDriver::$calls, fn ($call) => $call['platform'] === $platform));
}

beforeEach(function () {
    BuilderDriver::reset();
    CommentingDriver::reset();
    Carbon::setTestNow('2026-11-18 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Carbon::setTestNow();
});

// --- v1.0 surface -------------------------------------------------------------

it('counts postNow retries and restarts like the queued job', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        new TemporaryException('Discarded.', Platform::Facebook, FailureReason::ServerError, 0, ['restart' => true]),
        new TemporaryException('Busy.', Platform::Facebook, FailureReason::ServerError, 1),
        published(Platform::Facebook),
    ];

    SocialPoster::on('facebook')->using(creds())->caption('x')->postNow();

    $published = eventsOf($events, PostPublished::class)[0];

    // The restart is announced too, as the job announces it.
    expect(array_map(fn (PostRetrying $e) => [$e->attempt, $e->retries, $e->restarts], eventsOf($events, PostRetrying::class)))->toBe([[1, 1, 1], [1, 2, 1]])
        ->and([$published->attempt, $published->retries, $published->restarts])->toBe([2, 2, 1]);
});

it('reports postNow waits as the job does: create-step waits too, with reason and identifiers', function () {
    useDrivers(IdentifyingBuilderDriver::class, Platform::Facebook);
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'await', 'container_id' => 'c-1'], 5),
        PublishOutcome::commit(['phase' => 'create', 'container_id' => 'c-1'], 10)->withReason(PostProcessing::REASON_NOT_READY),
        PublishOutcome::commit(['phase' => 'create', 'container_id' => 'c-1']),
        published(Platform::Facebook),
    ];

    SocialPoster::on('facebook')->using(creds())->caption('x')->postNow();

    expect(array_map(fn (PostProcessing $e) => [$e->reason, $e->identifiers], eventsOf($events, PostProcessing::class)))->toBe([
        [PostProcessing::REASON_PROCESSING, ['container_id' => 'c-1']],
        [PostProcessing::REASON_NOT_READY, ['container_id' => 'c-1']],
    ])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('hands a postNow publish to the queue with its count, announcing the wait like the job', function (PublishOutcome $wait, array $announced) {
    config(['queue.default' => 'redis', 'social.sync.max_wait' => 30]);
    useDrivers(IdentifyingBuilderDriver::class, Platform::Facebook);
    Queue::fake();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        new TemporaryException('Busy.', Platform::Facebook, FailureReason::ServerError, 1),
        $wait,
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];

    $job = Queue::pushed(PublishToConnectionJob::class)->first();

    expect($result->payload['handed_off'] ?? null)->toBeTrue()
        ->and($job->priorRetries)->toBe(1)
        ->and(app(IdempotencyStore::class)->pendingState($job->idempotencyKey())['_retries'] ?? null)->toBe(1)
        ->and(array_map(fn (PostProcessing $e) => [$e->reason, $e->identifiers], eventsOf($events, PostProcessing::class)))->toBe([$announced]);
})->with([
    'a processing wait' => [
        PublishOutcome::pending(['phase' => 'await', 'container_id' => 'c-1'], 600),
        [PostProcessing::REASON_PROCESSING, ['container_id' => 'c-1']],
    ],
    'a create step waiting' => [
        PublishOutcome::commit(['phase' => 'create', 'container_id' => 'c-1'], 600)->withReason(PostProcessing::REASON_NOT_READY),
        [PostProcessing::REASON_NOT_READY, ['container_id' => 'c-1']],
    ],
]);

it('continues a recorded postNow run with its count', function () {
    useDrivers();
    $events = recordEvents();
    $key = PublishToConnectionJob::makeIdempotencyKey(Platform::Facebook, 'rec-1', 'page-1');
    app(IdempotencyStore::class)->markPending($key, ['phase' => 'await', '_retries' => 2, '_restarts' => 1, '_step' => 3, '_chain' => 'crashed']);

    SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('rec-1')->postNow();

    $published = eventsOf($events, PostPublished::class)[0];

    expect(callsFor('facebook')[0]['method'])->toBe('resume')
        ->and([$published->retries, $published->restarts])->toBe([2, 1]);
});

it('reports a postNow failure with its count and ids, and records the count', function () {
    useDrivers(IdentifyingBuilderDriver::class, Platform::Facebook);
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'await', 'container_id' => 'c-1'], 1),
        new TemporaryException('Busy.', Platform::Facebook, FailureReason::ServerError, 1),
        new PermanentException('Lost.', Platform::Facebook, FailureReason::OutcomeUnknown, ['possibly_published' => true]),
    ];

    SocialPoster::on('facebook')->using(creds())->caption('x')->postNow();

    $failed = eventsOf($events, PostFailed::class)[0];

    expect([$failed->retries, $failed->restarts])->toBe([1, 0])
        ->and($failed->exception->context['identifiers'] ?? null)->toBe(['container_id' => 'c-1'])
        ->and(app(IdempotencyStore::class)->record($failed->idempotencyKey)['state']['_retries'] ?? null)->toBe(1);
});

it('says a postNow verify step after a lost create is verifying, and starts the steps over on a restart', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'await'], 5),
        new TemporaryException('Discarded.', Platform::Facebook, FailureReason::ServerError, 0, ['restart' => true]),
        PublishOutcome::pending(['phase' => 'await'], 5),
        PublishOutcome::commit(['phase' => 'verify'], 5, null, true),
        published(Platform::Facebook),
    ];

    SocialPoster::on('facebook')->using(creds())->caption('x')->postNow();

    // As the job: after a restart, polls and elapsed time count from the restart.
    expect(array_map(fn (PostProcessing $e) => [$e->poll, $e->elapsedSeconds, $e->reason], eventsOf($events, PostProcessing::class)))->toBe([
        [1, 0, PostProcessing::REASON_PROCESSING],
        [1, 0, PostProcessing::REASON_PROCESSING],
        [2, 5, PostProcessing::REASON_VERIFYING],
    ]);
});

it('keeps the v1.0 call sites working: on/using/withOptions then post, postNow and validate', function () {
    useDrivers();
    Queue::fake();

    $builder = fn () => SocialPoster::on('facebook')
        ->using(['account_id' => 'page-1', 'page_access_token' => 'tok'])
        ->withOptions(new RawOptions(Platform::Facebook, ['k' => 'v']))
        ->caption('Hello');

    $validated = $builder()->validate();
    $queued = $builder()->post();
    $results = $builder()->postNow();

    expect($validated)->toHaveKey('facebook')
        ->and($validated['facebook'])->toBeInstanceOf(ValidationResult::class)
        ->and($validated['facebook']->passes())->toBeTrue()
        ->and($queued)->toBeInstanceOf(QueuedPost::class)
        ->and($queued->queued('facebook'))->toBeTrue()
        ->and(array_keys($results))->toBe(['facebook'])
        ->and($results['facebook']->success)->toBeTrue()
        ->and($results['facebook']->platformPostId)->toBe('facebook-post')
        ->and(BuilderDriver::$calls[0]['options']->extra())->toBe(['k' => 'v']);

    Queue::assertPushed(PublishToConnectionJob::class, 1);
});

it('accepts Platform cases and is lenient with case and whitespace', function () {
    useDrivers();

    $results = SocialPoster::on(Platform::Facebook, ' Instagram ')->using(creds())->caption('x')->postNow();

    expect(array_keys($results))->toBe(['facebook', 'instagram']);
});

// --- Platform selection -------------------------------------------------------

it('throws UnsupportedPlatformException for an unknown platform name', function () {
    expect(fn () => SocialPoster::on('myspace'))->toThrow(UnsupportedPlatformException::class);
});

it('reports a platform without a driver as a validation error instead of throwing', function () {
    useDrivers();

    $results = SocialPoster::on('facebook', 'mastodon')->caption('x')->validate();

    expect($results['facebook']->passes())->toBeTrue()
        ->and($results['mastodon']->fails())->toBeTrue()
        ->and($results['mastodon']->errors()->first('platform'))->toContain('mastodon');

    expect(fn () => SocialPoster::on('facebook', 'mastodon')->using(creds())->caption('x')->post())
        ->toThrow(ValidationException::class);

    expect(fn () => SocialPoster::on('mastodon')->using(['account' => 'a'])->withoutValidation()->post())
        ->toThrow(UnsupportedPlatformException::class);
});

it('never throws from validate() when a driver validation throws', function () {
    useDrivers();
    BuilderDriver::$validator = fn () => throw new RuntimeException('ffprobe hung');

    $result = SocialPoster::on('facebook')->caption('x')->validate()['facebook'];

    expect($result->fails())->toBeTrue()
        ->and($result->errors()->first('platform'))->toContain('ffprobe hung');
});

it('falls back to config social.default and throws when no platform is selected at all', function () {
    useDrivers();

    expect(fn () => SocialPoster::using(['account_id' => 'p', 'page_access_token' => 't'])->caption('x')->postNow())
        ->toThrow(PermanentException::class, 'No platform selected');

    config(['social.default' => 'facebook']);

    $results = SocialPoster::using(['account_id' => 'p', 'page_access_token' => 't'])->caption('x')->postNow();

    expect(array_keys($results))->toBe(['facebook'])
        ->and($results['facebook']->success)->toBeTrue();
});

it('throws Misconfigured when no credentials are supplied or configured', function () {
    useDrivers();

    try {
        SocialPoster::on('facebook')->caption('x')->post();
        $this->fail('Expected a PermanentException.');
    } catch (PermanentException $e) {
        expect($e->reason)->toBe(FailureReason::Misconfigured);
    }
});

// --- Per-platform credentials and options -------------------------------------

it('gives every platform its own credentials from a map', function () {
    useDrivers();

    SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    expect(callsFor('facebook')[0]['credentials'])->toBe(creds()['facebook'])
        ->and(callsFor('instagram')[0]['credentials'])->toBe(creds()['instagram']);
});

it('lets a platform-specific bag win over the shared one, and config fill the rest', function () {
    useDrivers();
    config(['social.platforms.linkedin.credentials' => creds()['linkedin']]);

    SocialPoster::on('facebook', 'instagram')
        ->using(['account_id' => 'shared', 'access_token' => 'shared-token'])
        ->using(Credentials::make(creds()['instagram']), 'instagram')
        ->caption('x')
        ->postNow();

    SocialPoster::on('linkedin')->caption('x')->postNow();

    expect(callsFor('facebook')[0]['credentials'])->toBe(['account_id' => 'shared', 'access_token' => 'shared-token'])
        ->and(callsFor('instagram')[0]['credentials'])->toBe(creds()['instagram'])
        ->and(callsFor('linkedin')[0]['credentials'])->toBe(creds()['linkedin']);
});

it('scopes options per platform and refuses two options objects for one platform', function () {
    useDrivers();

    SocialPoster::on('facebook', 'instagram', 'linkedin')
        ->using(creds())
        ->withOptions(new RawOptions(Platform::Facebook, ['fb' => 1]), new RawOptions(Platform::Instagram, ['ig' => 2]))
        ->caption('x')
        ->postNow();

    expect(callsFor('facebook')[0]['options']->extra())->toBe(['fb' => 1])
        ->and(callsFor('instagram')[0]['options']->extra())->toBe(['ig' => 2])
        ->and(callsFor('linkedin')[0]['options'])->toBeNull();

    expect(fn () => SocialPoster::on('facebook')->withOptions(
        new RawOptions(Platform::Facebook, ['a' => 1]),
        new RawOptions(Platform::Facebook, ['b' => 2]),
    ))->toThrow(InvalidArgumentException::class);
});

it('keeps the v1.0 single options slot: one options object is the post-level fallback', function () {
    $post = new SocialPost(caption: 'x', options: new RawOptions(Platform::X, ['legacy' => true]));
    $single = new PreparedPost(Platform::Instagram, $post, new Credentials);

    $map = new SocialPost(caption: 'x', platformOptions: [new RawOptions(Platform::Instagram, ['ig' => 1])]);

    expect($single->options()?->extra())->toBe(['legacy' => true])
        ->and((new PreparedPost(Platform::Instagram, $map, new Credentials))->options()?->extra())->toBe(['ig' => 1])
        ->and((new PreparedPost(Platform::LinkedIn, $map, new Credentials))->options())->toBeNull();
});

it('unserializes a SocialPost serialized by v1.0 (no per-platform map)', function () {
    $options = serialize(new RawOptions(Platform::Instagram, ['x' => 1]));
    $v10 = 'O:36:"SocialPoster\ValueObjects\SocialPost":6:{'
        .'s:5:"media";a:0:{}'
        .'s:7:"caption";s:5:"Hello";'
        .'s:5:"title";N;'
        .'s:7:"options";'.$options
        .'s:8:"metadata";a:1:{s:6:"record";i:7;}'
        .'s:7:"comment";s:3:"Hi!";'
        .'}';

    $post = unserialize($v10);

    expect($post)->toBeInstanceOf(SocialPost::class)
        ->and($post->caption)->toBe('Hello')
        ->and($post->metadata)->toBe(['record' => 7])
        ->and($post->comment)->toBe('Hi!')
        ->and($post->platformOptions())->toBe([])
        ->and((new PreparedPost(Platform::Instagram, $post, new Credentials))->options()?->extra())->toBe(['x' => 1]);

    $roundTrip = unserialize(serialize(new SocialPost(caption: 'y', platformOptions: [new RawOptions(Platform::X, ['x' => 2])])));

    expect($roundTrip->optionsFor(Platform::X)?->extra())->toBe(['x' => 2]);
});

// --- postNow: fan-out never aborts --------------------------------------------

it('turns an unexpected error on one platform into a failed result without losing the others', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [new TypeError('driver bug')];

    $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    expect($results['facebook']->success)->toBeFalse()
        ->and($results['facebook']->error)->toBeInstanceOf(PermanentException::class)
        ->and($results['facebook']->error->reason)->toBe(FailureReason::Unknown)
        ->and($results['instagram']->success)->toBeTrue()
        ->and(eventsOf($events, PostFailed::class))->toHaveCount(1)
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('retries a TemporaryException in-process, honouring Retry-After', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        new TemporaryException('busy', Platform::Facebook, FailureReason::RateLimited, 3),
        new TemporaryException('busy again', Platform::Facebook, FailureReason::ServerError),
        published(Platform::Facebook),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];

    expect($result->success)->toBeTrue()
        ->and(callsFor('facebook'))->toHaveCount(3)
        ->and(eventsOf($events, PostRetrying::class))->toHaveCount(2);

    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 3);
    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 5);
});

it('gives up after the retries and never waits past the synchronous budget', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = array_fill(0, 5, new TemporaryException('down', Platform::Facebook, FailureReason::ServerError));
    BuilderDriver::$scripts['instagram'] = [new TemporaryException('slow down', Platform::Instagram, FailureReason::RateLimited, 900)];

    $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    expect($results['facebook']->success)->toBeFalse()
        ->and($results['facebook']->error)->toBeInstanceOf(TemporaryException::class)
        ->and(callsFor('facebook'))->toHaveCount(4)
        ->and($results['instagram']->success)->toBeFalse()
        ->and(callsFor('instagram'))->toHaveCount(1)
        ->and(eventsOf($events, PostFailed::class))->toHaveCount(2);
});

it('polls a pending publish in-process with the reserved state keys', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'poll', 'video_id' => 'v1'], 10),
        published(Platform::Facebook),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];
    $resume = callsFor('facebook')[1];

    expect($result->success)->toBeTrue()
        ->and($resume['method'])->toBe('resume')
        ->and($resume['state']['phase'])->toBe('poll')
        ->and($resume['state']['_started_at'])->toBe(Carbon::parse('2026-11-18 12:00:00')->getTimestamp())
        ->and($resume['state']['_recovering'])->toBeFalse()
        ->and(eventsOf($events, PostProcessing::class))->toHaveCount(1);

    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 10);
});

it('flags a commit phase as recovering when its create may have gone out', function () {
    useDrivers();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::commit(['phase' => 'create']),
        new ConnectionException('cURL error 28: Operation timed out after 120000 ms'),
        published(Platform::Facebook),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:1')->postNow()['facebook'];
    $calls = callsFor('facebook');

    expect($result->success)->toBeTrue()
        ->and($calls)->toHaveCount(3)
        ->and($calls[1]['state']['_recovering'])->toBeFalse()
        ->and($calls[2]['state']['_recovering'])->toBeTrue()
        ->and($calls[2]['state'])->toHaveKey('_committing_since')
        ->and(SocialPoster::status('schedule:1', 'facebook', creds()['facebook']))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED)
        ->and(SocialPoster::outcome('schedule:1', 'facebook', creds()['facebook'])?->platformPostId)->toBe('facebook-post');
});

it('hands a long pending publish to the queue and reports it as processing', function () {
    config(['queue.default' => 'redis', 'social.sync.max_wait' => 30]);
    Queue::fake();
    useDrivers();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'poll'], 20),
        PublishOutcome::pending(['phase' => 'poll', 'step' => 2], 20, true),
    ];

    $started = Carbon::now()->getTimestamp();
    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->withMetadata(['record' => 3])->postNow()['facebook'];

    expect($result->success)->toBeTrue()
        ->and($result->isProcessing())->toBeTrue()
        ->and($result->isPublished())->toBeFalse()
        ->and($result->metadata)->toBe(['record' => 3])
        ->and($result->payload['handed_off'])->toBeTrue();

    Queue::assertPushed(PublishToConnectionJob::class, function (PublishToConnectionJob $job) use ($result, $started) {
        return $job->state === ['phase' => 'poll', 'step' => 2]
            && $job->pollCount === 2
            && $job->committed === true
            && $job->startedAt === $started
            && $job->idempotencyKey === $result->payload['idempotency_key']
            && $job->correlationId === $result->payload['correlation_id']
            && $job->delay === 20;
    });
});

it('times out instead of handing off on the sync queue, keeping a committed post possibly published', function () {
    config(['social.sync.max_wait' => 30]);
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [PublishOutcome::pending(['phase' => 'poll'], 60)];
    BuilderDriver::$scripts['instagram'] = [PublishOutcome::pending(['phase' => 'poll'], 60, true)];

    $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    expect($results['facebook']->error)->toBeInstanceOf(TemporaryException::class)
        ->and($results['facebook']->error->reason)->toBe(FailureReason::Timeout)
        ->and($results['instagram']->error->reason)->toBe(FailureReason::ProcessingTimeout)
        ->and($results['instagram']->error->possiblyPublished())->toBeTrue()
        ->and(eventsOf($events, PostFailed::class)[1]->possiblyPublished)->toBeTrue();
});

it('keeps uploading in postNow() past sync.max_wait: upload slices are work, not a wait (v1.0 uploaded inline)', function () {
    config(['social.sync.max_wait' => 120]);
    useDrivers();
    // Four 50 s upload slices, like YouTube's resumable upload, then the video.
    $slice = function () {
        Carbon::setTestNow(Carbon::now()->addSeconds(50));

        return PublishOutcome::pending(['phase' => 'upload'], 0, false, Carbon::now()->getTimestamp() + 3600);
    };
    BuilderDriver::$scripts['facebook'] = [$slice, $slice, $slice, $slice, published(Platform::Facebook, 'video-1')];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];

    expect($result->success)->toBeTrue()
        ->and($result->platformPostId)->toBe('video-1')
        ->and(BuilderDriver::$calls)->toHaveCount(5);
});

it('still stops postNow() upload slices at the driver deadline', function () {
    config(['social.sync.max_wait' => 120]);
    useDrivers();
    $deadline = Carbon::now()->getTimestamp() + 100;
    BuilderDriver::$scripts['facebook'] = array_fill(0, 10, function () use ($deadline) {
        Carbon::setTestNow(Carbon::now()->addSeconds(50));

        return PublishOutcome::pending(['phase' => 'upload'], 0, false, $deadline);
    });

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];

    expect($result->success)->toBeFalse()
        ->and($result->error->reason)->toBe(FailureReason::Timeout)
        ->and($result->error->getMessage())->toContain('uploaded')
        ->and(BuilderDriver::$calls)->toHaveCount(2);
});

it('runs a create that became ready at the end of the postNow() wait instead of timing out', function () {
    config(['social.sync.max_wait' => 30]);
    useDrivers();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::pending(['phase' => 'poll'], 20),
        function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(15));

            return PublishOutcome::commit(['phase' => 'create']);
        },
        published(Platform::Facebook, 'post-ready'),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->postNow()['facebook'];

    expect($result->success)->toBeTrue()
        ->and($result->platformPostId)->toBe('post-ready');
});

it('reports a verify step after an unclear create that outlives the sync wait as possibly published', function () {
    config(['social.sync.max_wait' => 30]);
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::commit(['phase' => 'pin']),
        PublishOutcome::commit(['phase' => 'verify_pin'], 60, null, true),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('pin:1')->postNow()['facebook'];

    expect($result->success)->toBeFalse()
        ->and($result->error->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($result->error->possiblyPublished())->toBeTrue()
        ->and(eventsOf($events, PostFailed::class)[0]->possiblyPublished)->toBeTrue()
        ->and(SocialPoster::status('pin:1', 'facebook', creds()['facebook']))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('does not ask for a reconnect in postNow() for a refusal a reconnect cannot change', function () {
    useDrivers();
    $rejected = [];
    Event::listen(CredentialsRejected::class, function ($event) use (&$rejected) {
        $rejected[] = $event;
    });
    BuilderDriver::$scripts['facebook'] = [new PermanentException('Privacy level not allowed.', Platform::Facebook, FailureReason::InsufficientPermissions, ['reconnect' => false])];
    BuilderDriver::$scripts['instagram'] = [new PermanentException('Missing scope.', Platform::Instagram, FailureReason::InsufficientPermissions)];

    $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    expect($results['facebook']->success)->toBeFalse()
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->platform)->toBe(Platform::Instagram);
});

it('never creates the post once the notAfter window closed', function () {
    useDrivers();

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->notAfter(Carbon::now()->subMinute())->postNow()['facebook'];

    expect($result->success)->toBeFalse()
        ->and($result->error->reason)->toBe(FailureReason::Expired)
        ->and(BuilderDriver::$calls)->toBeEmpty();
});

it('replays a postNow() published under the same idempotency key instead of posting again', function () {
    useDrivers();
    $events = recordEvents();

    $first = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:9')->postNow()['facebook'];
    $second = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:9')->postNow()['facebook'];

    $publishedEvents = eventsOf($events, PostPublished::class);

    expect(BuilderDriver::$calls)->toHaveCount(1)
        ->and($second->platformPostId)->toBe($first->platformPostId)
        ->and($publishedEvents[0]->replayed)->toBeFalse()
        ->and($publishedEvents[1]->replayed)->toBeTrue()
        ->and($publishedEvents[1]->idempotencyKey)->toBe($publishedEvents[0]->idempotencyKey);
});

it('runs validation per platform with bestEffort', function () {
    useDrivers();
    Queue::fake();
    $events = recordEvents();
    BuilderDriver::$errors['instagram'] = ['media' => ['Instagram needs media.']];

    expect(fn () => SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->post())
        ->toThrow(ValidationException::class, 'instagram.media');

    $queued = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->bestEffort()->post();
    $now = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->bestEffort()->postNow();

    expect($queued->queued('facebook'))->toBeTrue()
        ->and($queued->queued('instagram'))->toBeFalse()
        ->and($queued->errorFor('instagram'))->toBeInstanceOf(ValidationException::class)
        ->and($now['facebook']->success)->toBeTrue()
        ->and($now['instagram']->error)->toBeInstanceOf(ValidationException::class)
        ->and(eventsOf($events, PostFailed::class))->toHaveCount(2);

    Queue::assertPushed(PublishToConnectionJob::class, 1);
});

// --- postNow: first comment ---------------------------------------------------

it('queues the first comment after a postNow() by default, with the publish key', function () {
    Queue::fake();
    useDrivers(CommentingDriver::class);

    SocialPoster::on('facebook')->using(creds())->caption('x')->withComment('First!')->postNow();

    Queue::assertPushed(AddFirstCommentJob::class, function (AddFirstCommentJob $job) {
        return $job->comment === 'First!'
            && $job->postId === 'facebook-post'
            && $job->delay === 60
            && $job->idempotencyKey !== null
            && $job->post?->caption === 'x';
    });
});

it('keeps a live post successful when the inline comment hits a network error', function () {
    useDrivers(CommentingDriver::class);
    $events = recordEvents();
    CommentingDriver::$commentStep = new ConnectionException('cURL error 7: Failed to connect');

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->withComment('First!')->inlineComment()->postNow()['facebook'];

    expect($result->success)->toBeTrue()
        ->and(CommentingDriver::$comments)->toBe([['facebook-post', 'First!']])
        ->and(eventsOf($events, PostFailed::class))->toBeEmpty()
        ->and(eventsOf($events, CommentFailed::class))->toHaveCount(1);
});

it('queues the comment anyway when the platform needs a delay before commenting', function () {
    Queue::fake();
    useDrivers(CommentingDriver::class);
    CommentingDriver::$rules = new CommentRules(1250, null, 90);

    SocialPoster::on('facebook')->using(creds())->caption('x')->withComment('First!')->inlineComment()->postNow();

    expect(CommentingDriver::$comments)->toBe([]);
    Queue::assertPushed(AddFirstCommentJob::class, fn (AddFirstCommentJob $job) => $job->delay === 90);
});

it('skips the comment of a scheduled post and of a driver without comment support', function () {
    useDrivers(CommentingDriver::class, Platform::Facebook);
    useDrivers(BuilderDriver::class, Platform::Instagram);
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [published(Platform::Facebook, 'fb-1', PostResult::STATE_SCHEDULED)];

    SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->withComment('First!')->postNow();

    $reasons = array_map(fn ($event) => $event->reason, eventsOf($events, CommentSkipped::class));

    expect($reasons)->toBe([CommentSkipped::REASON_SCHEDULED, CommentSkipped::REASON_UNSUPPORTED])
        ->and(CommentingDriver::$comments)->toBe([]);
});

// --- post(): lock, announce, push ---------------------------------------------

it('computes the idempotency key once and carries it, the window and afterCommit on the job', function () {
    Queue::fake();
    useDrivers();
    $notAfter = Carbon::now()->addHour();

    $queued = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')
        ->idempotencyKey('schedule:5')->notAfter($notAfter)->afterCommit()->post();

    $expected = PublishToConnectionJob::idempotencyKeyFor(
        Platform::Instagram,
        new SocialPost(caption: 'x'),
        Credentials::make(creds()['instagram']),
        'schedule:5',
    );

    expect($queued->correlationId)->toBe('schedule:5')
        ->and($queued->key('instagram'))->toBe($expected)
        ->and($queued->key('facebook'))->not->toBe($expected);

    Queue::assertPushed(PublishToConnectionJob::class, function (PublishToConnectionJob $job) use ($queued, $notAfter) {
        return $job->idempotencyKey === $queued->key($job->platform)
            && $job->correlationId === 'schedule:5'
            && $job->notAfter === $notAfter->getTimestamp()
            && $job->afterCommit === true
            && $job->skipValidation === true;
    });
    Queue::assertPushed(PublishToConnectionJob::class, 2);
});

it('drops a second post() of the same key while the first is queued, using a real lock store', function () {
    Queue::fake();
    useDrivers();
    $events = recordEvents();

    $first = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:6')->post();
    $second = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:6')->post();

    expect($first->queued('facebook'))->toBeTrue()
        ->and($second->queued('facebook'))->toBeFalse()
        ->and($second->wasDeduplicated('facebook'))->toBeTrue()
        ->and(eventsOf($events, PostQueued::class))->toHaveCount(1)
        ->and(eventsOf($events, PostDeduplicated::class)[0]->key)->toBe($first->key('facebook'));

    Queue::assertPushed(PublishToConnectionJob::class, 1);
});

it('releases the lock and reports PostFailed when the queue push throws', function () {
    useDrivers();
    $events = recordEvents();
    $bus = app(BusDispatcher::class);
    app()->instance(BusDispatcher::class, new ThrowingBus(app()));

    $failed = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->idempotencyKey('schedule:7')->post();

    expect($failed->platforms)->toBe([])
        ->and($failed->errorFor('facebook'))->toBeInstanceOf(TemporaryException::class)
        ->and($failed->errorFor('facebook')->getMessage())->toContain('queue is down')
        ->and(eventsOf($events, PostFailed::class))->toHaveCount(2);

    app()->instance(BusDispatcher::class, $bus);
    Queue::fake();

    $retried = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:7')->post();

    expect($retried->queued('facebook'))->toBeTrue();
    Queue::assertPushed(PublishToConnectionJob::class, 1);
});

it('announces PostQueued before the job runs on the sync queue', function () {
    useDrivers();
    $events = recordEvents();

    $queued = SocialPoster::on('facebook')->using(creds())->caption('x')->withMetadata(['record' => 1])->post();
    $classes = array_map(fn ($event) => $event::class, $events->getArrayCopy());

    expect($classes)->toBe([PostQueued::class, PostPublished::class])
        ->and(BuilderDriver::$calls)->toHaveCount(1)
        ->and(eventsOf($events, PostPublished::class)[0]->correlationId)->toBe($queued->correlationId)
        ->and(eventsOf($events, PostPublished::class)[0]->idempotencyKey)->toBe($queued->key('facebook'))
        ->and(eventsOf($events, PostQueued::class)[0]->idempotencyKey)->toBe($queued->key('facebook'));
});

// --- Poster -------------------------------------------------------------------

it('exposes required scopes through the facade, with array credentials', function () {
    useDrivers();

    expect(SocialPoster::requiredScopes('facebook', Feature::Publish))->toBe([['scope.facebook']])
        ->and(SocialPoster::requiredScopes(Platform::Facebook, Feature::Publish, ['login' => 'business']))
        ->toBe([['scope.facebook'], ['business']])
        ->and(SocialPoster::requiredScopes('facebook', Feature::Comment, Credentials::make([])))->toBe([]);
});

// --- Review round: postNow and the idempotency store --------------------------

/** The store key a builder derives from ->idempotencyKey($key) for one platform and credentials bag. */
function storeKey(Platform $platform, array $credentials, string $key): string
{
    return PublishToConnectionJob::idempotencyKeyFor($platform, new SocialPost, Credentials::make($credentials), $key);
}

it('resumes a committing record as recovering instead of creating the post again (postNow)', function () {
    useDrivers();
    $store = app(IdempotencyStore::class);
    $key = storeKey(Platform::Facebook, creds()['facebook'], 'schedule:20');
    $since = Carbon::now()->subMinutes(20)->getTimestamp();

    // A run reached its create step and died there; its lease ran out.
    Carbon::setTestNow(Carbon::now()->subMinutes(20));
    $store->markCommitting($key, ['phase' => 'create', 'video_id' => 'v1', '_commits' => true, '_started_at' => $since - 30]);
    Carbon::setTestNow(Carbon::now()->addMinutes(20));

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:20')->postNow()['facebook'];
    $calls = callsFor('facebook');

    expect($result->success)->toBeTrue()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['method'])->toBe('resume')
        ->and($calls[0]['state']['phase'])->toBe('create')
        ->and($calls[0]['state']['video_id'])->toBe('v1')
        ->and($calls[0]['state']['_recovering'])->toBeTrue()
        ->and($calls[0]['state']['_committing_since'])->toBe($since)
        ->and($calls[0]['state']['_started_at'])->toBe($since - 30)
        ->and($store->status($key))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED);
});

it('resumes a pending record of a committed post instead of starting a second one (postNow)', function () {
    useDrivers();
    $store = app(IdempotencyStore::class);
    $key = storeKey(Platform::Facebook, creds()['facebook'], 'schedule:21');
    $store->markPending($key, ['phase' => 'poll', 'publish_id' => 'p1', '_committed' => true]);
    BuilderDriver::$scripts['facebook'] = [new PermanentException('The platform rejected the video.', Platform::Facebook, FailureReason::MediaRejected)];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:21')->postNow()['facebook'];
    $calls = callsFor('facebook');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['method'])->toBe('resume')
        ->and($calls[0]['state']['publish_id'])->toBe('p1')
        ->and($calls[0]['state']['_recovering'])->toBeFalse()
        // The restored marker says the post exists: a later failure may be live.
        ->and($result->error?->possiblyPublished())->toBeTrue();
});

it('replays a publish v1.0 recorded under "{key}:{platform}" instead of posting it again', function () {
    useDrivers();
    $events = recordEvents();
    $store = app(IdempotencyStore::class);
    $store->markPublished('schedule:10:facebook', PostResult::success(Platform::Facebook, 'fb-v10'));
    $store->markPublished('schedule:11:instagram', PostResult::success(Platform::Instagram, 'ig-v10'));

    $now = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:10')->postNow()['facebook'];
    $queued = SocialPoster::on('instagram')->using(creds())->caption('x')->idempotencyKey('schedule:11')->post();
    $published = eventsOf($events, PostPublished::class);

    expect(BuilderDriver::$calls)->toBe([])
        ->and($now->platformPostId)->toBe('fb-v10')
        ->and($queued->queued('instagram'))->toBeTrue()
        ->and($published)->toHaveCount(2)
        ->and($published[0]->replayed)->toBeTrue()
        ->and($published[1]->replayed)->toBeTrue()
        ->and($published[1]->result->platformPostId)->toBe('ig-v10')
        // Copied under the 1.1 key, so later lookups find it directly.
        ->and($store->completed(storeKey(Platform::Facebook, creds()['facebook'], 'schedule:10'))?->platformPostId)->toBe('fb-v10');
});

it('records a postNow() without an idempotency key, so the key its events report can be reconciled', function () {
    useDrivers();
    $events = recordEvents();
    BuilderDriver::$scripts['facebook'] = [
        PublishOutcome::commit(['phase' => 'create']),
        ...array_fill(0, 4, new TemporaryException('Gateway timeout.', Platform::Facebook, FailureReason::ServerError, null, ['http_status' => 504])),
    ];

    SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

    $failed = eventsOf($events, PostFailed::class)[0];
    $published = eventsOf($events, PostPublished::class)[0];

    expect($failed->possiblyPublished)->toBeTrue()
        ->and(SocialPoster::status($failed->idempotencyKey))->toBe(AtomicIdempotencyStore::STATUS_FAILED)
        ->and(SocialPoster::status($published->idempotencyKey))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED)
        ->and(SocialPoster::outcome($published->idempotencyKey)?->platformPostId)->toBe('instagram-post');
});

it('replays a published record through reconcile() without borrowing the configured account', function () {
    useDrivers();
    $events = recordEvents();
    config(['social.platforms.facebook.credentials' => ['account_id' => 'company-page', 'page_access_token' => 'company-token']]);

    $customer = ['account_id' => 'customer-page', 'page_access_token' => 'customer-token'];
    $queued = SocialPoster::on('facebook')->using($customer)->caption('x')->idempotencyKey('k')->post();

    expect(SocialPoster::reconcile($queued->key('facebook')))->toBe(Poster::RECONCILE_REPLAYED)
        ->and(eventsOf($events, PostFailed::class))->toBe([])
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(2)
        ->and(eventsOf($events, PostPublished::class)[1]->replayed)->toBeTrue();

    // The configured credentials are still used when they are the recorded account's.
    Queue::fake();
    SocialPoster::on('facebook')->caption('x')->idempotencyKey('own')->postNow();

    expect(SocialPoster::reconcile(storeKey(Platform::Facebook, config('social.platforms.facebook.credentials'), 'own')))
        ->toBe(Poster::RECONCILE_REPLAYED);
    Queue::assertPushed(PublishToConnectionJob::class, 1);
    Queue::assertPushed(PublishToConnectionJob::class, fn (PublishToConnectionJob $job) => $job->credentials->get('account_id') === 'company-page');
});

it('keys metadata-resolved credentials by the resolved account, so one key serves two accounts', function () {
    useDrivers();
    config(['social.credentials_resolver' => MetadataResolver::class]);

    $now1 = SocialPoster::on('instagram')->withMetadata(['account' => 1])->caption('x')->idempotencyKey('schedule:30')->postNow()['instagram'];
    $now2 = SocialPoster::on('instagram')->withMetadata(['account' => 2])->caption('x')->idempotencyKey('schedule:30')->postNow()['instagram'];

    expect(callsFor('instagram'))->toHaveCount(2)
        ->and(callsFor('instagram')[1]['credentials']['account_id'])->toBe('acct-2')
        ->and(SocialPoster::on('instagram')->withMetadata(['account' => 2])->idempotencyKey('schedule:30')->outcome()['instagram'])->not->toBeNull();

    Queue::fake();
    $a = SocialPoster::on('instagram')->withMetadata(['account' => 1])->caption('x')->idempotencyKey('schedule:31')->post();
    $b = SocialPoster::on('instagram')->withMetadata(['account' => 2])->caption('x')->idempotencyKey('schedule:31')->post();

    expect($a->key('instagram'))->toBe(PublishToConnectionJob::makeIdempotencyKey(Platform::Instagram, 'schedule:31', 'acct-1'))
        ->and($b->key('instagram'))->toBe(PublishToConnectionJob::makeIdempotencyKey(Platform::Instagram, 'schedule:31', 'acct-2'))
        ->and($b->queued('instagram'))->toBeTrue()
        ->and($b->wasDeduplicated('instagram'))->toBeFalse();

    // The resolved token never rides on the job.
    Queue::assertPushed(PublishToConnectionJob::class, 2);
    Queue::assertPushed(PublishToConnectionJob::class, fn (PublishToConnectionJob $job) => $job->credentials->values === []);
});

// --- Review round: transactions -----------------------------------------------

/**
 * A real database queue on its own connection: a job deferred to the commit
 * of a transaction on the default connection is discarded on its rollback,
 * while a job pushed before the commit stays in this table.
 */
function useDatabaseQueueOnItsOwnConnection(): void
{
    config([
        'database.connections.queue_db' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'queue_db', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 9999],
    ]);

    Schema::connection('queue_db')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

it('pushes the continuation of a committed post even when the surrounding transaction rolls back', function () {
    useDatabaseQueueOnItsOwnConnection();
    config(['social.sync.max_wait' => 30]);
    useDrivers();
    BuilderDriver::$scripts['facebook'] = [PublishOutcome::pending(['phase' => 'poll'], 60, true)];
    BuilderDriver::$scripts['instagram'] = [PublishOutcome::pending(['phase' => 'poll'], 60)];

    try {
        DB::transaction(function () {
            $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->postNow();

            expect($results['facebook']->isProcessing())->toBeTrue();

            throw new RuntimeException('The app rolls back for its own reasons.');
        });
    } catch (RuntimeException) {
    }

    // The platform has the Facebook post: its continuation must survive. The
    // Instagram one created nothing yet, so after_commit still drops it.
    $payloads = DB::connection('queue_db')->table('jobs')->pluck('payload')->all();

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0])->toContain('PublishToConnectionJob');
});

it('pushes a reconcile recovery job even when the surrounding transaction rolls back', function () {
    useDrivers();
    $key = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:41')->post()->key('facebook');
    useDatabaseQueueOnItsOwnConnection();

    try {
        DB::transaction(function () use ($key) {
            expect(SocialPoster::reconcile($key))->toBe(Poster::RECONCILE_REPLAYED);

            throw new RuntimeException('The app rolls back for its own reasons.');
        });
    } catch (RuntimeException) {
    }

    expect(DB::connection('queue_db')->table('jobs')->count())->toBe(1);
});

it('never leaves the unique lock or PostQueued behind a rolled-back transaction when the framework cannot release it', function () {
    useDrivers();
    Queue::fake();
    $events = recordEvents();
    $builder = fn () => (new NoRollbackHookBuilder(app(SocialManager::class), app('events'), config('social')))
        ->on('facebook')->using(creds())->caption('x')->idempotencyKey('schedule:40');

    try {
        DB::transaction(function () use ($builder) {
            $builder()->post();

            throw new RuntimeException('Deadlock found when trying to get lock.');
        });
    } catch (RuntimeException) {
    }

    expect(eventsOf($events, PostQueued::class))->toBe([]);
    Queue::assertNothingPushed();

    $retried = DB::transaction(fn () => $builder()->post());

    expect($retried->queued('facebook'))->toBeTrue()
        ->and($retried->wasDeduplicated('facebook'))->toBeFalse()
        ->and(eventsOf($events, PostQueued::class))->toHaveCount(1);
    Queue::assertPushed(PublishToConnectionJob::class, 1);

    // Outside a transaction nothing is deferred: a duplicate is still dropped at once.
    $duplicate = $builder()->post();

    expect($duplicate->wasDeduplicated('facebook'))->toBeTrue();
});

// --- Review round: comments on the sync connection ----------------------------

it('waits the platform minimum before the first comment on the sync queue connection', function () {
    useDrivers(CommentingDriver::class);
    CommentingDriver::$rules = new CommentRules(1250, null, 60);
    $started = Carbon::now()->getTimestamp();

    $result = SocialPoster::on('linkedin')->using(creds())->caption('x')->withComment('First!')->postNow()['linkedin'];

    expect($result->success)->toBeTrue()
        ->and(CommentingDriver::$comments)->toBe([['linkedin-post', 'First!']])
        ->and(CommentingDriver::$commentedAt[0] - $started)->toBeGreaterThanOrEqual(60);
});

it('reports CommentFailed instead of commenting too early when the sync wait cannot cover the minimum', function () {
    config(['social.sync.max_wait' => 30]);
    useDrivers(CommentingDriver::class);
    $events = recordEvents();
    CommentingDriver::$rules = new CommentRules(1250, null, 60);

    $result = SocialPoster::on('linkedin')->using(creds())->caption('x')->withComment('First!')->postNow()['linkedin'];
    $failed = eventsOf($events, CommentFailed::class);

    expect($result->success)->toBeTrue()
        ->and(CommentingDriver::$comments)->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception->getMessage())->toContain('sync');
});

// --- Review round: platforms and drivers ---------------------------------------

it('explains a registered custom name that is not a Platform case instead of contradicting itself', function () {
    app(SocialManager::class)->extend('reddit', fn () => new BuilderDriver(Platform::Facebook));

    foreach ([fn () => SocialPoster::on('reddit'), fn () => SocialPoster::status('k', 'reddit')] as $call) {
        try {
            $call();
            $this->fail('Expected an UnsupportedPlatformException.');
        } catch (UnsupportedPlatformException $e) {
            expect($e->registered)->not->toContain('reddit')
                ->and($e->registered)->toContain('facebook')
                ->and($e->getMessage())->not->toMatch('/Registered drivers: [^.]*reddit/')
                ->and($e->getMessage())->toContain('not a '.Platform::class.' case')
                ->and($e->getMessage())->not->toContain('No social-poster driver is registered for [reddit]')
                ->and($e->context['hint'] ?? '')->toContain('not a '.Platform::class.' case');
        }
    }
});

it('reports a driver class that is not a SocialPlatform per platform instead of crashing the fan-out', function () {
    useDrivers();
    app(SocialManager::class)->extend('instagram', fn () => new NotADriver);
    $events = recordEvents();

    $validated = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->validate();

    expect($validated['instagram']->errors()->first('platform'))->toContain(NotADriver::class);

    try {
        SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->withoutValidation()->postNow();
        $this->fail('Expected a PermanentException.');
    } catch (PermanentException $e) {
        expect($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->getMessage())->toContain(NotADriver::class);
    }

    expect(BuilderDriver::$calls)->toBe([]);

    $results = SocialPoster::on('facebook', 'instagram')->using(creds())->caption('x')->bestEffort()->postNow();

    expect($results['facebook']->success)->toBeTrue()
        ->and($results['instagram']->success)->toBeFalse()
        ->and($results['instagram']->error?->reason)->toBe(FailureReason::Misconfigured)
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('replays a row v1.0 wrote to the database store for the same key after the upgrade', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    config(['social.idempotency' => DatabaseIdempotencyStore::class]);
    app()->forgetInstance(IdempotencyStore::class);
    useDrivers();
    $events = recordEvents();

    // The row exactly as v1.0's DatabaseIdempotencyStore::markPublished() wrote it.
    DB::table('social_idempotency')->insert([
        'key' => 'schedule:12:linkedin',
        'status' => 'published',
        'platform' => 'linkedin',
        'platform_post_id' => 'urn:li:share:12',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:share:12',
        'payload' => json_encode([]),
        'state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $queued = SocialPoster::on('linkedin')->using(creds())->caption('x')->idempotencyKey('schedule:12')->post();
    $now = SocialPoster::on('linkedin')->using(creds())->caption('x')->idempotencyKey('schedule:12')->postNow()['linkedin'];

    expect(BuilderDriver::$calls)->toBe([])
        ->and($queued->queued('linkedin'))->toBeTrue()
        ->and($now->platformPostId)->toBe('urn:li:share:12')
        ->and(array_map(fn ($event) => $event->replayed, eventsOf($events, PostPublished::class)))->toBe([true, true])
        ->and(SocialPoster::outcome('schedule:12', 'linkedin', creds()['linkedin'])?->platformPostId)->toBe('urn:li:share:12');
});

// --- Review round: the postNow() lease over a long run --------------------------

/** One upload slice that takes $seconds and hands the next offset on. */
function uploadSlice(int $seconds = 100): Closure
{
    return function (PreparedPost $post, ?array $state) use ($seconds) {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));

        return PublishOutcome::pending(['phase' => 'upload', 'offset' => ($state['offset'] ?? 0) + 1], 0, false, Carbon::now()->getTimestamp() + 3600);
    };
}

it('renews the postNow() lease over a long upload, so a same-key post() meanwhile steps aside', function () {
    // Lease: job_timeout 100 + sync.max_wait 30 + 60 = 190 s, far shorter than the upload.
    config(['social.job_timeout' => 100, 'social.sync.max_wait' => 30]);
    useDrivers();
    $events = recordEvents();
    $concurrent = null;

    BuilderDriver::$scripts['facebook'] = [
        uploadSlice(),
        uploadSlice(),
        uploadSlice(),
        function (PreparedPost $post, ?array $state) use (&$concurrent) {
            // 400 s in: another process dispatches the same post on the queue.
            $outcome = uploadSlice()($post, $state);
            $concurrent = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('upload:1')->post();

            return $outcome;
        },
        published(Platform::Facebook, 'video-1'),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('upload:1')->postNow()['facebook'];
    $publishes = array_filter(callsFor('facebook'), fn (array $call) => $call['method'] === 'publish');

    expect($result->platformPostId)->toBe('video-1')
        ->and($publishes)->toHaveCount(1)
        ->and(callsFor('facebook'))->toHaveCount(5)
        ->and($concurrent?->queued('facebook'))->toBeTrue()
        ->and(eventsOf($events, PostDeduplicated::class))->toHaveCount(1)
        ->and(eventsOf($events, PostPublished::class))->toHaveCount(1);
});

it('records every postNow() step, so a same-key dispatch that takes over a stalled run resumes it instead of publishing again', function () {
    config(['social.job_timeout' => 100, 'social.sync.max_wait' => 30]);
    useDrivers();
    $events = recordEvents();
    $started = Carbon::now()->getTimestamp();

    BuilderDriver::$scripts['facebook'] = [
        uploadSlice(),
        uploadSlice(),
        function (PreparedPost $post, ?array $state) {
            // This process stalls past its lease (a frozen VM, a long GC pause)
            // and another dispatch of the same post takes the key over.
            Carbon::setTestNow(Carbon::now()->addSeconds(500));
            SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('upload:2')->post();

            return PublishOutcome::pending(['phase' => 'upload', 'offset' => $state['offset'] + 1], 0, false, Carbon::now()->getTimestamp() + 3600);
        },
        // The taking-over job's step.
        published(Platform::Facebook, 'video-2'),
    ];

    $result = SocialPoster::on('facebook')->using(creds())->caption('x')->idempotencyKey('upload:2')->postNow()['facebook'];
    $calls = callsFor('facebook');
    $published = eventsOf($events, PostPublished::class);

    expect(array_column($calls, 'method'))->toBe(['publish', 'resume', 'resume', 'resume'])
        // The job continued from the last recorded slice, on the original clock.
        ->and($calls[3]['state']['offset'])->toBe(2)
        ->and($calls[3]['state']['_started_at'])->toBe($started)
        // The stalled run steps aside and reports the post the job published.
        ->and($result->platformPostId)->toBe('video-2')
        ->and($published)->toHaveCount(2)
        ->and($published[0]->replayed)->toBeFalse()
        ->and($published[1]->replayed)->toBeTrue();
});

// --- Review round: one idempotency key, credentials that name no account --------

/** Warnings logged from here on (the once-per-process memo is reset). */
function loggedWarnings(): ArrayObject
{
    config(['logging.default' => 'null']);
    (new ReflectionProperty(PostBuilder::class, 'warnedUnidentified'))->setValue(null, []);
    $log = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($log) {
        if ($event->level === 'warning') {
            $log[] = $event->message;
        }
    });

    return $log;
}

it('warns, once, when an idempotency key is used with credentials that name no account', function () {
    useDrivers(UserIdDriver::class, Platform::X);
    $warnings = loggedWarnings();
    $tokenOnly = fn (string $token) => SocialPoster::on('x')->using(['access_token' => $token])->caption('x')->idempotencyKey('content:1');

    $tokenOnly('tok-a')->postNow();
    $tokenOnly('tok-b')->post();
    $validated = $tokenOnly('tok-b')->validate()['x'];

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain("'user_id'")
        ->and($warnings[0])->toContain("'account'")
        ->and($warnings[0])->toContain('another x account')
        // validate() says so too, without failing the content.
        ->and($validated->passes())->toBeTrue()
        ->and($validated->hasWarnings())->toBeTrue()
        ->and($validated->warnings()->first('idempotency'))->toContain("'user_id'")
        ->and($validated->toArray()['warnings']['idempotency'])->toHaveCount(1);
});

it('does not warn when the credentials name the account or no idempotency key is given', function () {
    useDrivers(UserIdDriver::class, Platform::X);
    $warnings = loggedWarnings();

    SocialPoster::on('x')->using(['access_token' => 'tok-a', 'user_id' => '42'])->caption('x')->idempotencyKey('content:2')->postNow();
    SocialPoster::on('x')->using(['access_token' => 'tok-a', 'account' => 'brand-a'])->caption('x')->idempotencyKey('content:3')->post();
    SocialPoster::on('x')->using(['access_token' => 'tok-a'])->caption('x')->postNow();
    $validated = SocialPoster::on('x')->using(['access_token' => 'tok-a'])->caption('x')->validate()['x'];
    $identified = SocialPoster::on('x')->using(['access_token' => 'tok-a', 'user_id' => '42'])->caption('x')->idempotencyKey('content:2')->validate()['x'];

    expect($warnings)->toHaveCount(0)
        ->and($validated->hasWarnings())->toBeFalse()
        ->and($identified->hasWarnings())->toBeFalse()
        ->and($validated->toArray())->toBe(['platform' => 'x', 'passes' => true, 'errors' => []]);
});
