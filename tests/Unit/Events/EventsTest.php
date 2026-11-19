<?php

namespace SocialPoster\Tests\Unit\Events;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
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
use SocialPoster\Events\PostQueued;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\SocialPost;

/** Records what a queued listener actually receives after the queue round trip. */
class RecordingQueuedListener implements ShouldQueue
{
    /** @var list<object> */
    public static array $received = [];

    public function handle(object $event): void
    {
        self::$received[] = $event;
    }
}

/**
 * The string PHP produced for $class in v1.0, before __serialize() existed.
 *
 * @param  array<string, mixed>  $props
 */
function legacyPayload(string $class, array $props): string
{
    return 'O:'.strlen($class).':"'.$class.'":'.substr(serialize($props), 2);
}

/**
 * Run $callback with stack-frame arguments recorded (the PHP CLI default, and
 * the setting under which a trace captures closures).
 */
function withTraceArgs(Closure $callback): mixed
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        return $callback();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
}

/** A TemporaryException created inside a frame that received a Closure, wrapping a transport error. */
function exceptionWithClosureInTrace(): TemporaryException
{
    $make = static function (Closure $pipelineStage): TemporaryException {
        $previous = (static fn (Closure $next) => new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'))(static fn () => null);

        return new TemporaryException(
            'Facebook is rate limiting this app.',
            Platform::Facebook,
            FailureReason::RateLimited,
            120,
            ['code' => 4, 'fbtrace_id' => 'AbC123', 'possibly_published' => false, 'nested' => ['ok' => 1, 'callback' => $pipelineStage]],
            $previous,
        );
    };

    return $make(static fn () => 'stage');
}

function makePost(): SocialPost
{
    return new SocialPost([Media::image('https://cdn.example.com/a.jpg')], 'Hello world');
}

it('keeps every v1.0 constructor position and defaults the new fields', function () {
    $error = new PermanentException('Token expired', Platform::Instagram, FailureReason::InvalidToken);
    $result = PostResult::success(Platform::Instagram, '179');

    $published = new PostPublished($result);
    $failed = new PostFailed(Platform::Instagram, $error, ['row' => 1]);
    $queued = new PostQueued(Platform::Instagram, makePost(), ['row' => 1]);
    $commentPublished = new CommentPublished(Platform::Instagram, '179', '180', ['row' => 1]);
    $commentFailed = new CommentFailed(Platform::Instagram, '179', $error, ['row' => 1]);

    expect($published->result)->toBe($result)
        ->and([$published->correlationId, $published->idempotencyKey, $published->attempt, $published->replayed])->toBe([null, null, 1, false])
        ->and($failed->metadata)->toBe(['row' => 1])
        ->and([$failed->correlationId, $failed->idempotencyKey, $failed->attempt, $failed->willRetry, $failed->possiblyPublished])->toBe([null, null, 1, false, false])
        ->and($queued->metadata)->toBe(['row' => 1])
        ->and([$queued->correlationId, $queued->idempotencyKey])->toBe([null, null])
        ->and($commentPublished->commentId)->toBe('180')
        ->and([$commentPublished->url, $commentPublished->payload])->toBe([null, []])
        ->and($commentFailed->exception)->toBe($error)
        ->and($commentFailed->attempt)->toBe(1);
});

it('takes the new trailing fields in contract order', function () {
    $error = new TemporaryException('Timed out', Platform::X, FailureReason::OutcomeUnknown);
    $result = PostResult::success(Platform::X, '1');

    $published = new PostPublished($result, 'corr-1', 'key-1', 3, true);
    $failed = new PostFailed(Platform::X, $error, [], 'corr-1', 'key-1', 5, false, true);
    $queued = new PostQueued(Platform::X, makePost(), [], 'corr-1', 'key-1');
    $commentPublished = new CommentPublished(Platform::Bluesky, 'at://p', 'at://c', [], 'https://bsky.app/profile/x/post/c', ['cid' => 'bafy']);
    $commentFailed = new CommentFailed(Platform::X, '1', $error, [], 4);

    expect([$published->correlationId, $published->idempotencyKey, $published->attempt, $published->replayed])->toBe(['corr-1', 'key-1', 3, true])
        ->and([$failed->correlationId, $failed->idempotencyKey, $failed->attempt, $failed->willRetry, $failed->possiblyPublished])->toBe(['corr-1', 'key-1', 5, false, true])
        ->and([$queued->correlationId, $queued->idempotencyKey])->toBe(['corr-1', 'key-1'])
        ->and([$commentPublished->url, $commentPublished->payload])->toBe(['https://bsky.app/profile/x/post/c', ['cid' => 'bafy']])
        ->and($commentFailed->attempt)->toBe(4);
});

it('builds the new lifecycle events', function () {
    $summary = (new TemporaryException('Server error', Platform::LinkedIn, FailureReason::ServerError, 60))->errorSummary();
    $at = new DateTimeImmutable('2026-11-18T12:00:00+00:00');

    $retrying = new PostRetrying(Platform::LinkedIn, $summary, 2, $at, ['row' => 1], 'corr-1');
    $retryingText = new PostRetrying(Platform::LinkedIn, ['reason' => 'not-a-reason'], 1, '2026-11-18T12:00:00Z');
    $processing = new PostProcessing(Platform::YouTube, 4, 310, ['row' => 1], 'corr-1');
    $deduplicated = new PostDeduplicated(Platform::Threads, 'key-1', ['row' => 1], 'corr-1');
    $skipped = new CommentSkipped(Platform::Instagram, null, CommentSkipped::REASON_NO_POST_ID, ['row' => 1]);
    $rejected = new CredentialsRejected(Platform::Threads, 'acct-17841', FailureReason::InvalidToken, true, ['row' => 1], $summary);

    expect($retrying->errorSummary)->toBe($summary)
        ->and($retrying->errorSummary())->toBe($summary)
        ->and($retrying->reason())->toBe(FailureReason::ServerError)
        ->and($retrying->attempt)->toBe(2)
        ->and($retrying->nextRetryAt)->toBe($at)
        ->and($retrying->correlationId)->toBe('corr-1')
        ->and($retryingText->nextRetryAt)->toBe('2026-11-18T12:00:00Z')
        ->and($retryingText->reason())->toBeNull()
        ->and($retryingText->metadata)->toBe([])
        ->and([$processing->poll, $processing->elapsedSeconds, $processing->metadata])->toBe([4, 310, ['row' => 1]])
        ->and([$deduplicated->key, $deduplicated->correlationId])->toBe(['key-1', 'corr-1'])
        ->and([$skipped->postId, $skipped->reason])->toBe([null, 'no_post_id'])
        ->and([$rejected->accountKey, $rejected->reason, $rejected->terminal])->toBe(['acct-17841', FailureReason::InvalidToken, true])
        ->and($rejected->errorSummary())->toBe($summary)
        ->and((new CredentialsRejected(Platform::X, 'acct', FailureReason::InsufficientPermissions))->terminal)->toBeFalse();
});

it('lists exactly the contract comment-skip reasons', function () {
    expect(CommentSkipped::reasons())->toBe([
        'unsupported', 'story', 'missing_scope', 'empty', 'no_post_id', 'scheduled', 'already_done', 'account_mismatch',
    ]);
});

it('exposes a serialisable errorSummary() on events that carry an exception', function () {
    $error = new PermanentException('Missing scope', null, FailureReason::InsufficientPermissions, ['code' => 10, 'response' => new \stdClass]);

    foreach ([new PostFailed(Platform::Facebook, $error), new CommentFailed(Platform::Facebook, '1_2', $error)] as $event) {
        $summary = $event->errorSummary();

        expect($summary)->toMatchArray([
            'platform' => 'facebook',
            'reason' => 'insufficient_permissions',
            'category' => 'reconnect',
            'message' => 'Missing scope',
            'context' => ['code' => 10],
        ])
            ->and(unserialize(serialize($summary)))->toBe($summary)
            ->and(json_encode($summary))->toBeString();
    }

    $own = new PermanentException('x', Platform::Threads, FailureReason::Unknown);

    expect((new PostFailed(Platform::Facebook, $own))->errorSummary()['platform'])->toBe('threads');
});

it('round-trips every event through serialize()', function (Closure $make) {
    $event = $make();
    $restored = unserialize(serialize($event));

    expect($restored)->toBeInstanceOf($event::class);

    foreach (get_object_vars($event) as $name => $value) {
        if ($value instanceof SocialPosterException) {
            expect($restored->{$name})->toBeInstanceOf($value::class)
                ->and($restored->{$name}->getMessage())->toBe($value->getMessage())
                ->and($restored->{$name}->reason)->toBe($value->reason);
        } elseif (is_object($value)) {
            expect($restored->{$name})->toEqual($value);
        } else {
            expect($restored->{$name})->toBe($value);
        }
    }
})->with([
    'PostPublished' => [fn () => new PostPublished(PostResult::success(Platform::TikTok, 'v1', null, ['a' => 1], 'draft', 'publish_id'), 'c', 'k', 2, true)],
    'PostFailed' => [fn () => new PostFailed(Platform::X, new TemporaryException('t', Platform::X, FailureReason::Network, 5), ['m' => 1], 'c', 'k', 2, true, true)],
    'PostQueued' => [fn () => new PostQueued(Platform::X, makePost(), ['m' => 1], 'c', 'k')],
    'CommentPublished' => [fn () => new CommentPublished(Platform::Bluesky, 'p', 'c', ['m' => 1], 'https://bsky.app/x', ['cid' => 'b'])],
    'CommentFailed' => [fn () => new CommentFailed(Platform::LinkedIn, 'p', new PermanentException('p'), ['m' => 1], 3)],
    'PostRetrying' => [fn () => new PostRetrying(Platform::X, ['reason' => 'rate_limited'], 1, new DateTimeImmutable('2026-01-01T00:00:00Z'), ['m' => 1], 'c')],
    'PostProcessing' => [fn () => new PostProcessing(Platform::YouTube, 3, 90, ['m' => 1], 'c')],
    'PostDeduplicated' => [fn () => new PostDeduplicated(Platform::Threads, 'k', ['m' => 1], 'c')],
    'CommentSkipped' => [fn () => new CommentSkipped(Platform::TikTok, '1', 'unsupported', ['m' => 1])],
    'CredentialsRejected' => [fn () => new CredentialsRejected(Platform::X, 'a', FailureReason::InvalidToken, true, ['m' => 1], ['reason' => 'invalid_token'])],
]);

it('serialises an event whose exception trace and context hold closures', function () {
    withTraceArgs(function () {
        $error = exceptionWithClosureInTrace();

        expect(fn () => serialize($error))->toThrow(\Exception::class, 'Closure');

        $restored = unserialize(serialize(new PostFailed(Platform::Facebook, $error, ['row' => 7], 'corr', 'key', 2)));
        $copy = $restored->exception;

        expect($copy)->toBeInstanceOf(TemporaryException::class)
            ->and($copy->getMessage())->toBe($error->getMessage())
            ->and($copy->getFile())->toBe($error->getFile())
            ->and($copy->getLine())->toBe($error->getLine())
            ->and($copy->platform)->toBe(Platform::Facebook)
            ->and($copy->reason)->toBe(FailureReason::RateLimited)
            ->and($copy->retryAfter)->toBe(120)
            ->and($copy->context)->toBe(['code' => 4, 'fbtrace_id' => 'AbC123', 'possibly_published' => false, 'nested' => ['ok' => 1]])
            ->and($copy->getPrevious())->toBeInstanceOf(ConnectionException::class)
            ->and($copy->getPrevious()?->getMessage())->toContain('cURL error 28')
            ->and(count($copy->getTrace()))->toBe(count($error->getTrace()))
            ->and(array_filter($copy->getTrace(), fn ($frame) => array_key_exists('args', $frame)))->toBe([])
            ->and($restored->errorSummary())->toBe((new PostFailed(Platform::Facebook, $error))->errorSummary())
            ->and([$restored->metadata, $restored->correlationId, $restored->idempotencyKey, $restored->attempt])->toBe([['row' => 7], 'corr', 'key', 2]);

        // The live exception is left untouched for whoever still holds it.
        expect(array_key_exists('args', $error->getTrace()[0]))->toBeTrue()
            ->and($error->context['nested']['callback'])->toBeInstanceOf(Closure::class);
    });
});

it('copies an exception that serialises as is with its whole trace, minus frame arguments', function () {
    // Production php.ini sets exception_ignore_args, so traces carry no closures.
    $previous = ini_set('zend.exception_ignore_args', '1');

    try {
        $error = new PermanentException('plain', Platform::X, FailureReason::DuplicateContent, ['code' => 187]);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    expect(fn () => serialize($error))->not->toThrow(\Exception::class);

    $restored = unserialize(serialize(new CommentFailed(Platform::X, '1', $error)));

    // Always a copy: frame arguments can hold a PreparedPost and its Credentials.
    $withoutArgs = array_map(static fn (array $frame) => array_diff_key($frame, ['args' => true]), $error->getTrace());

    expect($restored->exception->getTrace())->toEqual($withoutArgs)
        ->and($restored->exception->getTrace())->not->toBeEmpty()
        ->and($restored->exception->getMessage())->toBe('plain')
        ->and($restored->exception->context)->toBe(['code' => 187]);
});

it('reduces nested context to plain values in errorSummary()', function () {
    $error = new PermanentException('x', Platform::Pinterest, FailureReason::Unknown, [
        'code' => 1,
        'nested' => ['ok' => true, 'fn' => static fn () => null, 'deeper' => ['obj' => new \stdClass, 'n' => null]],
    ]);

    $summary = (new PostFailed(Platform::Pinterest, $error))->errorSummary();

    expect($summary['context'])->toBe(['code' => 1, 'nested' => ['ok' => true, 'deeper' => ['n' => null]]])
        ->and(unserialize(serialize($summary)))->toBe($summary)
        ->and(PostResult::failed(Platform::Pinterest, $error)->toArray()['error'])->toBe($summary);
});

it('unserializes events queued by v1.0 with the new fields defaulted', function (string $class, array $legacy, array $defaults) {
    $restored = unserialize(legacyPayload($class, $legacy));

    expect($restored)->toBeInstanceOf($class);

    foreach ($legacy as $name => $value) {
        expect($restored->{$name})->toEqual($value);
    }

    foreach ($defaults as $name => $value) {
        expect($restored->{$name})->toBe($value);
    }
})->with([
    'PostPublished' => [PostPublished::class, ['result' => PostResult::success(Platform::X, '1')], ['correlationId' => null, 'idempotencyKey' => null, 'attempt' => 1, 'replayed' => false]],
    'PostFailed' => [PostFailed::class, ['platform' => Platform::X, 'exception' => new PermanentException('p'), 'metadata' => ['m' => 1]], ['correlationId' => null, 'idempotencyKey' => null, 'attempt' => 1, 'willRetry' => false, 'possiblyPublished' => false]],
    'PostQueued' => [PostQueued::class, ['platform' => Platform::X, 'post' => new SocialPost([], 'hi'), 'metadata' => []], ['correlationId' => null, 'idempotencyKey' => null]],
    'CommentPublished' => [CommentPublished::class, ['platform' => Platform::Facebook, 'postId' => '1_2', 'commentId' => '3', 'metadata' => []], ['url' => null, 'payload' => []]],
    'CommentFailed' => [CommentFailed::class, ['platform' => Platform::Facebook, 'postId' => '1_2', 'exception' => new PermanentException('p'), 'metadata' => []], ['attempt' => 1]],
]);

it('reads a v1.0 PostPublished whose result predates state and idKind', function () {
    $result = legacyPayload(PostResult::class, [
        'platform' => Platform::Facebook,
        'success' => true,
        'platformPostId' => '1_2',
        'url' => null,
        'payload' => [],
        'error' => null,
        'metadata' => [],
    ]);
    $event = 'O:'.strlen(PostPublished::class).':"'.PostPublished::class.'":1:{s:6:"result";'.$result.'}';

    $restored = unserialize($event);

    expect($restored->result->platformPostId)->toBe('1_2')
        ->and($restored->result->isScheduled())->toBeFalse()
        ->and($restored->replayed)->toBeFalse();
});

it('delivers PostFailed to a queued listener when the exception trace holds closures', function () {
    RecordingQueuedListener::$received = [];
    Event::listen(PostFailed::class, RecordingQueuedListener::class);

    $dispatched = withTraceArgs(function () {
        // The sync queue serialises the listener job exactly like a real queue.
        $event = new PostFailed(Platform::Facebook, exceptionWithClosureInTrace(), ['row' => 3], 'corr-9', 'key-9', 5, false, true);
        event($event);

        return $event;
    });

    expect(RecordingQueuedListener::$received)->toHaveCount(1);

    $event = RecordingQueuedListener::$received[0];

    expect($event)->toBeInstanceOf(PostFailed::class)
        ->and($event)->not->toBe($dispatched)
        ->and($event->exception)->toBeInstanceOf(TemporaryException::class)
        ->and($event->exception)->not->toBe($dispatched->exception)
        ->and(array_filter($event->exception->getTrace(), fn ($frame) => array_key_exists('args', $frame)))->toBe([])
        ->and($event->errorSummary()['reason'])->toBe('rate_limited')
        ->and([$event->metadata, $event->correlationId, $event->idempotencyKey, $event->attempt, $event->possiblyPublished])
        ->toBe([['row' => 3], 'corr-9', 'key-9', 5, true]);
});

it('delivers the new events to a queued listener', function () {
    RecordingQueuedListener::$received = [];
    Event::listen([PostRetrying::class, CommentSkipped::class, CredentialsRejected::class], RecordingQueuedListener::class);

    event(new PostRetrying(Platform::X, ['reason' => 'rate_limited'], 1, new DateTimeImmutable('2026-01-01T00:00:00Z')));
    event(new CommentSkipped(Platform::Instagram, '179', CommentSkipped::REASON_STORY));
    event(new CredentialsRejected(Platform::Threads, 'acct', FailureReason::InvalidToken, true));

    expect(array_map(fn ($event) => $event::class, RecordingQueuedListener::$received))
        ->toBe([PostRetrying::class, CommentSkipped::class, CredentialsRejected::class])
        ->and(RecordingQueuedListener::$received[2]->reason)->toBe(FailureReason::InvalidToken);
});

// --- Secrets never reach a queued payload -----------------------------------------

/** A Laravel transport failure as PendingRequest builds it: Guzzle's exception, holding the request, as previous. */
function transportFailureWithToken(string $token): ConnectionException
{
    $request = new Request('POST', 'https://graph.facebook.com/v25.0/1/feed?access_token='.$token, ['Authorization' => 'Bearer '.$token]);
    $guzzle = new ConnectException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received', $request, null, ['errno' => 28]);

    return new ConnectionException($guzzle->getMessage(), 0, $guzzle);
}

it('keeps the bearer token out of a queued PostFailed or CommentFailed for a network failure', function () {
    withTraceArgs(function () {
        $network = TemporaryException::connection(Platform::Facebook, transportFailureWithToken('EAAB-SECRET-TOKEN'));
        // A driver wrapping the transport error itself (LinkedIn's lost create).
        $wrapped = new PermanentException('lost', Platform::LinkedIn, FailureReason::OutcomeUnknown, ['possibly_published' => true], transportFailureWithToken('AQX-SECRET-TOKEN'));

        foreach ([new PostFailed(Platform::Facebook, $network), new CommentFailed(Platform::Facebook, '1_2', $network), new PostFailed(Platform::LinkedIn, $wrapped)] as $event) {
            $payload = serialize($event);

            expect($payload)->not->toContain('SECRET-TOKEN');

            $restored = unserialize($payload);

            expect($restored->exception->getMessage())->toBe($event->exception->getMessage())
                ->and($restored->exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
        }
    });
});

it('drops the transport request from a connection failure but keeps what retry decisions need', function () {
    $sent = TemporaryException::connection(Platform::X, transportFailureWithToken('tok-1'));
    $unsent = TemporaryException::connection(Platform::X, new ConnectionException('cURL error 6: Could not resolve host: api.x.com'));

    expect($sent->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and($sent->getPrevious()?->getPrevious())->toBeNull()
        ->and($sent->getPrevious()?->getMessage())->toContain('cURL error 28')->not->toContain('tok-1')
        ->and($sent->context)->toMatchArray(['request_sent' => true, 'curl_errno' => 28])
        ->and(TemporaryException::requestNeverSent($sent))->toBeFalse()
        ->and($unsent->context['request_sent'])->toBeFalse()
        ->and(TemporaryException::requestNeverSent($unsent))->toBeTrue();
});
