<?php

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * Builds the string PHP produced for $class before it had __serialize(), i.e. a
 * payload queued by v1.0 that a v1.1 worker must still read.
 *
 * @param  array<string, mixed>  $props
 */
function outcomesLegacyPayload(string $class, array $props): string
{
    return 'O:'.strlen($class).':"'.$class.'":'.substr(serialize($props), 2);
}

/** A PostResult with a non-default value in every field. */
function outcomesFullResult(): PostResult
{
    return new PostResult(
        Platform::TikTok,
        true,
        '7300000000000000001',
        'https://www.tiktok.com/@creator/video/7300000000000000001',
        ['publish_id' => 'v_pub_123', 'status' => 'PUBLISH_COMPLETE'],
        new PermanentException('kept in memory only', Platform::TikTok, FailureReason::MediaRejected, ['code' => 'x']),
        ['scheduled_post_id' => 10],
        PostResult::STATE_PROCESSING,
        'video_id',
    );
}

it('keeps the v1.0 Pending constructor and defaults the new flags', function () {
    $pending = new Pending(['phase' => 'poll'], 45);

    expect($pending->state)->toBe(['phase' => 'poll'])
        ->and($pending->recheckAfter)->toBe(45)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->committed)->toBeFalse()
        ->and($pending->deadline)->toBeNull()
        ->and((new Pending([]))->recheckAfter)->toBe(30);
});

it('carries commits, committed and deadline in the contract order', function () {
    $pending = new Pending(['phase' => 'publish'], 5, true, true, 1_900_000_000);

    expect($pending->commits)->toBeTrue()
        ->and($pending->committed)->toBeTrue()
        ->and($pending->deadline)->toBe(1_900_000_000);
});

it('builds pending and commit outcomes through PublishOutcome', function () {
    $legacy = PublishOutcome::pending(['phase' => 'poll']);
    $tiktok = PublishOutcome::pending(['phase' => 'poll', 'publish_id' => 'p1'], 15, committed: true, deadline: 1_900_003_900);
    $commit = PublishOutcome::commit(['phase' => 'publish', 'container_id' => 'c1']);
    $later = PublishOutcome::commit(['phase' => 'publish'], 10, 1_900_000_000);

    expect($legacy)->toBeInstanceOf(Pending::class)
        ->and($legacy->recheckAfter)->toBe(30)
        ->and($legacy->commits)->toBeFalse()
        ->and($legacy->committed)->toBeFalse()
        ->and($tiktok->committed)->toBeTrue()
        ->and($tiktok->commits)->toBeFalse()
        ->and($tiktok->deadline)->toBe(1_900_003_900)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->committed)->toBeFalse()
        ->and($commit->recheckAfter)->toBe(0)
        ->and($commit->state)->toBe(['phase' => 'publish', 'container_id' => 'c1'])
        ->and($later->recheckAfter)->toBe(10)
        ->and($later->deadline)->toBe(1_900_000_000);
});

it('swaps the state but keeps every flag with withState()', function () {
    $pending = new Pending(['phase' => 'publish'], 7, true, true, 123);
    $copy = $pending->withState(['phase' => 'publish', Pending::RECOVERING => true]);

    expect($copy)->not->toBe($pending)
        ->and($copy->state)->toBe(['phase' => 'publish', '_recovering' => true])
        ->and([$copy->recheckAfter, $copy->commits, $copy->committed, $copy->deadline])->toBe([7, true, true, 123])
        ->and($pending->state)->toBe(['phase' => 'publish']);
});

it('names the reserved state keys exactly as the contract does', function () {
    expect(Pending::RECOVERING)->toBe('_recovering')
        ->and(Pending::COMMITTING_SINCE)->toBe('_committing_since')
        ->and(Pending::STARTED_AT)->toBe('_started_at')
        ->and(Pending::ATTEMPT)->toBe('_attempt')
        ->and(Pending::RESTARTS)->toBe('_restarts')
        ->and(Pending::isReservedKey('_recovering'))->toBeTrue()
        ->and(Pending::isReservedKey('phase'))->toBeFalse();
});

it('strips job-owned keys and reads the recovering flag', function () {
    $state = [
        'phase' => 'publish',
        'container_id' => '178',
        '_recovering' => true,
        '_committing_since' => 1_800_000_000,
        '_started_at' => 1_799_999_000,
        '_attempt' => 2,
        '_restarts' => 0,
    ];

    expect(Pending::withoutReservedKeys($state))->toBe(['phase' => 'publish', 'container_id' => '178'])
        ->and(Pending::isRecovering($state))->toBeTrue()
        ->and(Pending::isRecovering(['phase' => 'publish']))->toBeFalse()
        ->and(Pending::isRecovering(['_recovering' => false]))->toBeFalse()
        ->and(Pending::withoutReservedKeys([0 => 'list', '_x' => 1]))->toBe([0 => 'list']);
});

it('keeps the v1.0 PostResult constructor and factories', function () {
    $success = PostResult::success(Platform::X, '123', 'https://x.com/i/web/status/123', ['text' => 'hi']);
    $error = new TemporaryException('slow down', Platform::X, FailureReason::RateLimited, 60);
    $failed = PostResult::failed(Platform::X, $error);
    $positional = new PostResult(Platform::LinkedIn, true, 'urn:li:share:1', null, [], null, ['a' => 1]);

    expect($success->success)->toBeTrue()
        ->and($success->platformPostId)->toBe('123')
        ->and($success->payload)->toBe(['text' => 'hi'])
        ->and($success->state)->toBeNull()
        ->and($success->idKind)->toBeNull()
        ->and($failed->success)->toBeFalse()
        ->and($failed->error)->toBe($error)
        ->and($failed->state)->toBeNull()
        ->and($positional->metadata)->toBe(['a' => 1])
        ->and($positional->state)->toBeNull();
});

it('accepts state and idKind as trailing success() arguments', function () {
    $result = PostResult::success(Platform::TikTok, 'v_pub_1', null, ['public_id_pending' => true], PostResult::STATE_PROCESSING, 'publish_id');

    expect($result->state)->toBe('processing')
        ->and($result->idKind)->toBe('publish_id')
        ->and($result->error)->toBeNull()
        ->and($result->metadata)->toBe([]);
});

it('answers the state predicates', function (?string $state, bool $success, array $expected) {
    $result = new PostResult(Platform::YouTube, $success, 'abc', state: $state);

    expect([
        'published' => $result->isPublished(),
        'scheduled' => $result->isScheduled(),
        'draft' => $result->isDraft(),
        'processing' => $result->isProcessing(),
    ])->toBe($expected);
})->with([
    'v1.0 driver (null)' => [null, true, ['published' => true, 'scheduled' => false, 'draft' => false, 'processing' => false]],
    'published' => ['published', true, ['published' => true, 'scheduled' => false, 'draft' => false, 'processing' => false]],
    'scheduled' => ['scheduled', true, ['published' => false, 'scheduled' => true, 'draft' => false, 'processing' => false]],
    'draft' => ['draft', true, ['published' => false, 'scheduled' => false, 'draft' => true, 'processing' => false]],
    'private' => ['private', true, ['published' => false, 'scheduled' => false, 'draft' => false, 'processing' => false]],
    'processing' => ['processing', true, ['published' => false, 'scheduled' => false, 'draft' => false, 'processing' => true]],
    'failed' => [null, false, ['published' => false, 'scheduled' => false, 'draft' => false, 'processing' => false]],
]);

it('copies every field in withMetadata(), including ones added later', function () {
    $original = outcomesFullResult();
    $copy = $original->withMetadata(['job' => 'abc']);

    expect($copy->metadata)->toBe(['job' => 'abc'])
        ->and($original->metadata)->toBe(['scheduled_post_id' => 10]);

    // Walk the constructor so a field added in a later release cannot be forgotten.
    foreach ((new ReflectionMethod(PostResult::class, '__construct'))->getParameters() as $parameter) {
        $name = $parameter->getName();

        if ($name !== 'metadata') {
            expect($copy->{$name})->toBe($original->{$name}, "withMetadata() dropped {$name}");
        }
    }
});

it('round-trips every field except the error through toArray() and fromArray()', function () {
    $original = outcomesFullResult();
    $array = $original->toArray();
    $restored = PostResult::fromArray($array);

    expect($array)->toMatchArray([
        'platform' => 'tiktok',
        'success' => true,
        'platform_post_id' => '7300000000000000001',
        'url' => 'https://www.tiktok.com/@creator/video/7300000000000000001',
        'state' => 'processing',
        'id_kind' => 'video_id',
        'payload' => ['publish_id' => 'v_pub_123', 'status' => 'PUBLISH_COMPLETE'],
        'metadata' => ['scheduled_post_id' => 10],
    ])
        ->and($array['error'])->toMatchArray(['reason' => 'media_rejected', 'message' => 'kept in memory only'])
        ->and(json_decode((string) json_encode($original), true))->toBe(json_decode((string) json_encode($array), true));

    foreach ((new ReflectionMethod(PostResult::class, '__construct'))->getParameters() as $parameter) {
        $name = $parameter->getName();

        expect($restored->{$name})->toBe($name === 'error' ? null : $original->{$name}, "fromArray() lost {$name}");
    }
});

it('survives a JSON round trip, as a store would persist it', function () {
    $original = PostResult::success(Platform::Facebook, '1_2', 'https://facebook.com/1_2', ['id' => '1_2'], PostResult::STATE_SCHEDULED)
        ->withMetadata(['row' => 5]);

    $restored = PostResult::fromArray(json_decode((string) json_encode($original->toArray()), true));

    expect($restored->isScheduled())->toBeTrue()
        ->and($restored->platformPostId)->toBe('1_2')
        ->and($restored->url)->toBe('https://facebook.com/1_2')
        ->and($restored->payload)->toBe(['id' => '1_2'])
        ->and($restored->metadata)->toBe(['row' => 5]);
});

it('reads v1.0 database rows and camelCase arrays in fromArray()', function () {
    // A v1.0 social_idempotency row: payload is a JSON column, no success/state/id_kind.
    $row = PostResult::fromArray([
        'platform' => 'instagram',
        'platform_post_id' => 17900000000000000,
        'url' => 'https://www.instagram.com/p/abc/',
        'payload' => '{"id":"17900000000000000"}',
    ]);

    $camel = PostResult::fromArray(get_object_vars(PostResult::success(Platform::X, '9', null, [], 'published', 'tweet_id')));

    expect($row->success)->toBeTrue()
        ->and($row->platform)->toBe(Platform::Instagram)
        ->and($row->platformPostId)->toBe('17900000000000000')
        ->and($row->payload)->toBe(['id' => '17900000000000000'])
        ->and($row->state)->toBeNull()
        ->and($row->idKind)->toBeNull()
        ->and($row->metadata)->toBe([])
        ->and($camel->platformPostId)->toBe('9')
        ->and($camel->state)->toBe('published')
        ->and($camel->idKind)->toBe('tweet_id')
        ->and(PostResult::fromArray(['platform' => Platform::Threads, 'payload' => 'not json'])->payload)->toBe([]);
});

it('refuses fromArray() without a known platform', function (array $data) {
    PostResult::fromArray($data);
})->with([
    'missing' => [['platform_post_id' => '1']],
    'unknown' => [['platform' => 'myspace']],
])->throws(InvalidArgumentException::class);

it('round-trips through serialize() with every field', function () {
    $original = outcomesFullResult();
    $restored = unserialize(serialize($original));

    expect($restored)->toBeInstanceOf(PostResult::class)
        ->and($restored->state)->toBe('processing')
        ->and($restored->idKind)->toBe('video_id')
        ->and($restored->payload)->toBe($original->payload)
        ->and($restored->metadata)->toBe($original->metadata)
        ->and($restored->error)->toBeInstanceOf(PermanentException::class)
        ->and($restored->error?->reason)->toBe(FailureReason::MediaRejected);
});

it('unserializes a PostResult queued by v1.0 with the new fields defaulted', function () {
    $payload = outcomesLegacyPayload(PostResult::class, [
        'platform' => Platform::Facebook,
        'success' => true,
        'platformPostId' => '1_2',
        'url' => 'https://facebook.com/1_2',
        'payload' => ['id' => '1_2'],
        'error' => null,
        'metadata' => ['row' => 5],
    ]);

    $restored = unserialize($payload);

    expect($restored->platformPostId)->toBe('1_2')
        ->and($restored->metadata)->toBe(['row' => 5])
        ->and($restored->state)->toBeNull()
        ->and($restored->idKind)->toBeNull()
        ->and($restored->isScheduled())->toBeFalse()
        ->and($restored->isPublished())->toBeTrue();
});

it('stays serialisable when its error trace holds closures', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $error = (static function (Closure $callback): PermanentException {
            return new PermanentException('rejected', Platform::X, FailureReason::InvalidContent, ['fn' => $callback, 'code' => 186]);
        })(static fn () => null);

        expect(fn () => serialize($error))->toThrow(Exception::class, 'Closure');

        $restored = unserialize(serialize(PostResult::failed(Platform::X, $error)));

        expect($restored->error)->toBeInstanceOf(PermanentException::class)
            ->and($restored->error?->getMessage())->toBe('rejected')
            ->and($restored->error?->context)->toBe(['code' => 186]);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('still wraps the result in Published', function () {
    $result = PostResult::success(Platform::Bluesky, 'at://did:plc:x/app.bsky.feed.post/1');

    expect(PublishOutcome::published($result))->toBeInstanceOf(Published::class)
        ->and(PublishOutcome::published($result)->result)->toBe($result);
});
