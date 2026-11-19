<?php

namespace SocialPoster\Tests\Feature\BuilderIntegration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\PostBuilder;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;

/*
 * Builder behaviour that sits between the drivers and the jobs: the first
 * comment is validated against the driver's CommentRules before anything is
 * published, and postNow() deletes this process's temporary media when it ends.
 */

/** A Facebook stand-in with first-comment rules; optionally reports comment errors itself. */
class CommentRulesDriver extends AbstractPlatform implements SupportsFirstComment
{
    public static ?CommentRules $rules = null;

    public static bool $ownCommentCheck = false;

    /** Outcome of publish(): published, or still processing. */
    public static bool $processing = false;

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
        $errors = new MessageBag;

        if (static::$ownCommentCheck && $post->comment() !== null) {
            $errors->add('comment', 'The driver checked the comment itself.');
        }

        return $errors;
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        return static::$processing
            ? $this->pending(['phase' => 'poll'], 600)
            : PublishOutcome::published(PostResult::success(Platform::Facebook, 'fb-1'));
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->pending(['phase' => 'poll'], 600);
    }

    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        return static::$rules;
    }

    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        return new CommentResult('c-1');
    }
}

/** Counts cleanup() calls. */
class CountingGateway implements MediaGateway
{
    public int $cleanups = 0;

    public function canProvideUrl(Media $media): bool
    {
        return true;
    }

    public function url(Media $media): string
    {
        return $media->source;
    }

    public function path(Media $media): string
    {
        return $media->source;
    }

    public function cleanup(): void
    {
        $this->cleanups++;
    }
}

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

beforeEach(function () {
    CommentRulesDriver::$rules = new CommentRules(20);
    CommentRulesDriver::$ownCommentCheck = false;
    CommentRulesDriver::$processing = false;
    app(SocialManager::class)->extend('facebook', fn () => new CommentRulesDriver);
    Sleep::fake();
});

function fbBuilder(?string $comment): PostBuilder
{
    return SocialPoster::on('facebook')
        ->using(['account_id' => 'page-1', 'page_access_token' => 'token'])
        ->caption('Hello')
        ->withComment($comment);
}

// --- First comment validation --------------------------------------------------

it('validates the first comment against the driver CommentRules before publishing', function () {
    $results = fbBuilder(str_repeat('a', 21))->validate();

    expect($results['facebook']->errors->get('comment'))->toHaveCount(1)
        ->and($results['facebook']->errors->first('comment'))->toContain('limit is 20');

    expect(fn () => fbBuilder(str_repeat('a', 21))->postNow())->toThrow(ValidationException::class);
});

it('accepts a comment within the limit, no comment, and a variant that takes no comment', function () {
    expect(fbBuilder(str_repeat('a', 20))->validate()['facebook']->errors->isEmpty())->toBeTrue()
        ->and(fbBuilder(null)->validate()['facebook']->errors->isEmpty())->toBeTrue();

    // A story or scheduled post: no rules, so nothing to check (the job skips the comment).
    CommentRulesDriver::$rules = null;

    expect(fbBuilder(str_repeat('a', 500))->validate()['facebook']->errors->isEmpty())->toBeTrue();
});

it('does not repeat a comment error the driver already reported', function () {
    CommentRulesDriver::$ownCommentCheck = true;

    expect(fbBuilder(str_repeat('a', 21))->validate()['facebook']->errors->get('comment'))
        ->toBe(['The driver checked the comment itself.']);
});

it('checks the comment on the real drivers that leave it to the builder', function (string $platform, array $credentials, array $media, int $limit) {
    app(SocialManager::class)->forgetDrivers();
    app()->forgetInstance(SocialManager::class);

    $builder = SocialPoster::on($platform)->using($credentials)->caption('Hello')->withComment(str_repeat('a', $limit + 1));

    if ($media !== []) {
        $builder->media(...$media);
    }

    config(['social.inspect_remote' => false]);

    $errors = $builder->validate()[$platform]->errors->get('comment');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain("the limit is {$limit}");
})->with([
    'facebook (8000)' => ['facebook', ['account_id' => 'page-1', 'page_access_token' => 'token'], [], 8000],
    'instagram (2200)' => ['instagram', ['account_id' => 'ig-1', 'access_token' => 'EAAtoken'], [Media::image('https://cdn.example.com/a.jpg')], 2200],
    'threads (500)' => ['threads', ['account_id' => 'th-1', 'access_token' => 'THAAtoken'], [], 500],
    'x (280, checked by the driver)' => ['x', ['access_token' => 'x-token'], [], 280],
]);

// --- Temporary media cleanup ---------------------------------------------------

it('deletes the gateway and transformer temp files when postNow() ends', function () {
    $gateway = new CountingGateway;
    $transformer = new CountingTransformer;
    app()->instance(MediaGateway::class, $gateway);
    app()->instance(MediaTransformer::class, $transformer);

    $results = fbBuilder(null)->postNow();

    expect($results['facebook']->success)->toBeTrue()
        ->and($gateway->cleanups)->toBe(1)
        ->and($transformer->cleanups)->toBe(1);
});

it('keeps temp files when a publish was handed to the queue', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 1200]]);
    Queue::fake();

    $gateway = new CountingGateway;
    app()->instance(MediaGateway::class, $gateway);
    CommentRulesDriver::$processing = true;

    $results = fbBuilder(null)->postNow();

    expect($results['facebook']->isProcessing())->toBeTrue()
        ->and($results['facebook']->payload['handed_off'] ?? null)->toBeTrue()
        ->and($gateway->cleanups)->toBe(0);
});
