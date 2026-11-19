<?php

namespace SocialPoster\Tests\Feature\Testing\SocialPosterFakeTest;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostQueued;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Poster;
use SocialPoster\Testing\SocialPosterFake;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\QueuedPost;
use SocialPoster\ValueObjects\RawOptions;

it('swaps the facade and the container binding for a recording fake', function () {
    $fake = SocialPoster::fake();

    expect($fake)->toBeInstanceOf(SocialPosterFake::class)
        ->and(app(Poster::class))->toBe($fake)
        ->and(SocialPoster::getFacadeRoot())->toBe($fake);

    $fake->assertNothingPosted();
});

it('records queued posts without touching drivers, credentials, HTTP or the queue', function () {
    Queue::fake();
    Http::fake();
    $fake = SocialPoster::fake();

    $queued = SocialPoster::on('instagram', 'mastodon')
        ->caption('Hello')
        ->withOptions(new RawOptions(Platform::Instagram, ['story' => true]))
        ->withMetadata(['record' => 1])
        ->idempotencyKey('schedule:1')
        ->post();

    expect($queued)->toBeInstanceOf(QueuedPost::class)
        ->and($queued->correlationId)->toBe('schedule:1')
        ->and($queued->queued('instagram'))->toBeTrue()
        ->and($queued->key('instagram'))->toBe('fake:instagram:schedule:1');

    $fake->assertQueued('instagram', fn (PreparedPost $post) => $post->caption() === 'Hello'
        && $post->options()?->extra() === ['story' => true]
        && $post->metadata() === ['record' => 1]);
    $fake->assertPosted('mastodon');
    $fake->assertPostedTimes('instagram', 1);
    $fake->assertNotPosted('x');
    $fake->assertNotPosted('instagram', fn (PreparedPost $post) => $post->caption() === 'Other');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('returns scripted outcomes from postNow()', function () {
    $fake = SocialPoster::fake()
        ->succeed('instagram', 'ig-1', 'https://instagram.com/p/1')
        ->fail('linkedin', FailureReason::InvalidToken)
        ->fail('x', FailureReason::RateLimited, 'Slow down', true)
        ->pending('tiktok');

    $results = SocialPoster::on('instagram', 'linkedin', 'x', 'tiktok', 'facebook')->caption('Hi')->postNow();

    expect($results['instagram']->platformPostId)->toBe('ig-1')
        ->and($results['instagram']->url)->toBe('https://instagram.com/p/1')
        ->and($results['linkedin']->success)->toBeFalse()
        ->and($results['linkedin']->error)->toBeInstanceOf(PermanentException::class)
        ->and($results['linkedin']->error->reason)->toBe(FailureReason::InvalidToken)
        ->and($results['x']->error)->toBeInstanceOf(TemporaryException::class)
        ->and($results['x']->error->getMessage())->toBe('Slow down')
        ->and($results['tiktok']->isProcessing())->toBeTrue()
        ->and($results['facebook']->success)->toBeTrue()
        ->and($results['facebook']->platformPostId)->toStartWith('fake-facebook-');

    $fake->assertPostedNow('instagram');
    expect(fn () => $fake->assertQueued('instagram'))->toThrow(AssertionFailedError::class);
});

it('records validation and applies scripted validation errors', function () {
    $fake = SocialPoster::fake()->rejectValidation('instagram', ['media' => 'Instagram needs media.']);

    $results = SocialPoster::on('instagram', 'facebook')->caption('Hi')->validate();

    expect($results['instagram']->fails())->toBeTrue()
        ->and($results['instagram']->errors()->first('media'))->toBe('Instagram needs media.')
        ->and($results['facebook']->passes())->toBeTrue();

    $fake->assertValidated();
    $fake->assertValidated('instagram', fn (PreparedPost $post) => $post->caption() === 'Hi');
    $fake->assertNothingPosted();

    expect(fn () => SocialPoster::on('instagram', 'facebook')->caption('Hi')->post())
        ->toThrow(ValidationException::class);

    $queued = SocialPoster::on('instagram', 'facebook')->caption('Hi')->bestEffort()->post();

    expect($queued->queued('facebook'))->toBeTrue()
        ->and($queued->errorFor('instagram'))->toBeInstanceOf(ValidationException::class);

    $fake->assertNotPosted('instagram');
    $fake->assertQueued('facebook');

    SocialPoster::on('instagram')->caption('Hi')->withoutValidation()->postNow();
    $fake->assertPostedNow('instagram');
});

it('asserts first comments', function () {
    $fake = SocialPoster::fake();

    SocialPoster::on('instagram')->caption('Hi')->withComment('Link in bio')->post();
    SocialPoster::on('facebook')->caption('Hi')->post();

    $fake->assertCommented('instagram');
    $fake->assertCommented('instagram', 'Link in bio');
    $fake->assertCommented('instagram', fn (string $text) => str_contains($text, 'bio'));

    expect(fn () => $fake->assertCommented('facebook'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertCommented('instagram', 'Other'))->toThrow(AssertionFailedError::class);
});

it('dispatches the package events when asked to', function () {
    Event::fake();
    SocialPoster::fake()->dispatchEvents()->fail('linkedin', FailureReason::InvalidToken)->pending('tiktok');

    SocialPoster::on('instagram', 'linkedin')->caption('Hi')->withMetadata(['record' => 2])->post();
    SocialPoster::on('tiktok')->caption('Hi')->postNow();

    Event::assertDispatched(PostQueued::class, 2);
    Event::assertDispatched(PostPublished::class, fn (PostPublished $event) => $event->result->platform === Platform::Instagram
        && $event->result->metadata === ['record' => 2]);
    Event::assertDispatched(PostFailed::class, fn (PostFailed $event) => $event->platform === Platform::LinkedIn
        && $event->exception->reason === FailureReason::InvalidToken);
    Event::assertDispatched(PostProcessing::class, 1);
});

it('dispatches no events by default', function () {
    Event::fake();
    SocialPoster::fake();

    SocialPoster::on('instagram')->caption('Hi')->post();

    Event::assertNothingDispatched();
});

it('answers outcome() and records reconcile() for faked publishes', function () {
    $fake = SocialPoster::fake()->succeed('instagram', 'ig-9');

    SocialPoster::on('instagram')->caption('Hi')->idempotencyKey('schedule:9')->postNow();

    expect(SocialPoster::outcome('schedule:9', 'instagram')?->platformPostId)->toBe('ig-9')
        ->and(SocialPoster::outcome('schedule:9', 'facebook'))->toBeNull()
        ->and(SocialPoster::on('instagram')->idempotencyKey('schedule:9')->outcome()['instagram']?->platformPostId)->toBe('ig-9')
        ->and(SocialPoster::reconcile('schedule:9', 'instagram'))->toBe(Poster::RECONCILE_REPLAYED)
        ->and(SocialPoster::reconcile('schedule:0'))->toBe(Poster::RECONCILE_NOTHING);

    $fake->assertReconciled('schedule:9');
    $fake->assertReconciled();
    expect(fn () => $fake->assertReconciled('other'))->toThrow(AssertionFailedError::class);
});

it('fails assertions with a readable message', function () {
    $fake = SocialPoster::fake();

    SocialPoster::on('instagram')->caption('Hi')->post();

    expect(fn () => $fake->assertPosted('facebook'))->toThrow(AssertionFailedError::class, '[facebook]')
        ->and(fn () => $fake->assertNothingPosted())->toThrow(AssertionFailedError::class, 'instagram')
        ->and(fn () => $fake->assertPostedTimes('instagram', 2))->toThrow(AssertionFailedError::class);
});
