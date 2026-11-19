<?php

use Illuminate\Support\MessageBag;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\LinkedIn\LinkedInMention;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;

afterEach(fn () => Kit::cleanup());

it('declares LinkedIn capabilities with the documented limits', function () {
    $caps = Kit::driver()->capabilities();

    expect($caps->caption->max)->toBe(3000)
        ->and($caps->media->maxCount)->toBe(20)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'gif', 'png'])
        ->and($caps->media->image->maxPixels)->toBe(36152319)
        ->and($caps->media->video->maxSizeBytes)->toBe(524288000)
        ->and($caps->media->video->minSizeBytes)->toBe(76800)
        ->and($caps->media->video->minDuration)->toBe(3.0)
        ->and($caps->media->video->maxDuration)->toBe(1800.0)
        ->and($caps->media->document->maxSizeBytes)->toBe(104857600)
        ->and($caps->supportsTitle)->toBeTrue()
        ->and($caps->supportsCarousel)->toBeTrue();
});

it('lets every limit be overridden through config', function () {
    config(['social.api.linkedin' => [
        'max_characters' => 1000, 'max_images' => 9, 'max_image_pixels' => 100, 'max_video_bytes' => 10,
        'min_video_bytes' => 5, 'min_video_duration' => 1, 'max_video_duration' => 60, 'max_document_bytes' => 7,
    ]]);

    $caps = Kit::driver()->capabilities();

    expect($caps->caption->max)->toBe(1000)
        ->and($caps->media->maxCount)->toBe(9)
        ->and($caps->media->image->maxPixels)->toBe(100)
        ->and($caps->media->video->maxSizeBytes)->toBe(10)
        ->and($caps->media->video->minSizeBytes)->toBe(5)
        ->and($caps->media->video->maxDuration)->toBe(60.0)
        ->and($caps->media->document->maxSizeBytes)->toBe(7);
});

it('validates a plain post without credentials', function () {
    $errors = Kit::driver()->validate(Kit::post(credentials: []));

    expect($errors->all())->toBe([]);
});

it('requires a caption except for a reshare', function () {
    expect(Kit::driver()->validate(Kit::post(caption: '  '))->first('caption'))->toBe("LinkedIn posts can't have an empty caption.")
        ->and(Kit::driver()->validate(Kit::post(caption: null, options: new LinkedInOptions(reshareOf: Kit::SHARE)))->all())->toBe([]);
});

it('measures the caption on the visible text when mentions are used', function () {
    $mention = [LinkedInMention::make(Kit::ORG, 'Acme')];
    $fits = str_repeat('a', 2996).'{{mention:0}}';      // 2996 + "Acme" = 3000 visible
    $tooLong = str_repeat('a', 2997).'{{mention:0}}';   // 3001 visible

    expect(Kit::driver()->validate(Kit::post(caption: $fits, options: new LinkedInOptions(mentions: $mention)))->all())->toBe([])
        ->and(Kit::driver()->validate(Kit::post(caption: $tooLong, options: new LinkedInOptions(mentions: $mention)))->first('caption'))
        ->toBe('Caption exceeds the 3000 character limit (currently 3001 visible characters).')
        ->and(Kit::driver()->validate(Kit::post(caption: str_repeat('a', 3001)))->has('caption'))->toBeTrue();
});

it('fails orphan mention markers instead of publishing them as text', function () {
    $none = Kit::driver()->validate(Kit::post(caption: 'Hi {{mention:0}}'));
    $missing = Kit::driver()->validate(Kit::post(
        caption: 'Hi {{mention:0}} and {{mention:2}} and {{mention:2}}',
        options: new LinkedInOptions(mentions: [LinkedInMention::make(Kit::ORG, 'Acme')]),
    ));

    expect($none->get('caption'))->toBe(['The caption contains {{mention:0}} but no mentions were given (LinkedInOptions::$mentions).'])
        ->and($missing->get('caption'))->toBe(['The caption contains {{mention:2}} but LinkedInOptions::$mentions has no entry 2.']);
});

it('checks the author shape only when an author is given', function (mixed $author, bool $error) {
    $errors = Kit::driver()->validate(Kit::post(credentials: $author === null ? [] : ['author' => $author]));

    expect($errors->has('credentials.author'))->toBe($error);
})->with([
    'missing' => [null, false],
    'blank' => ['', false],
    'person' => [Kit::PERSON, false],
    'organization' => [Kit::ORG, false],
    'company urn' => ['urn:li:company:1', true],
    'broken id' => ['urn:li:person:abc) x', true],
    'not a string' => [[Kit::PERSON], true],
]);

it('limits alt text to 4,086 characters', function () {
    $errors = Kit::driver()->validate(Kit::post([Media::image(Kit::file(10, 'png'), str_repeat('x', 4087)), Kit::image(str_repeat('x', 4086))]));

    expect($errors->has('media.0'))->toBeTrue()
        ->and($errors->has('media.1'))->toBeFalse();
});

it('refuses link cards, polls and reshares combined with media', function () {
    $options = new LinkedInOptions(article: ['source' => 'https://example.com', 'title' => 'T']);
    $poll = new LinkedInOptions(poll: ['question' => 'Q?', 'options' => ['A', 'B']]);
    $reshare = new LinkedInOptions(reshareOf: Kit::SHARE);

    expect(Kit::driver()->validate(Kit::post([Kit::image()], options: $options))->first('article'))->toBe("A LinkedIn link card can't be combined with media.")
        ->and(Kit::driver()->validate(Kit::post([Kit::image()], options: $poll))->first('poll'))->toBe("A LinkedIn poll can't be combined with media.")
        ->and(Kit::driver()->validate(Kit::post([Kit::image()], options: $reshare))->first('reshareOf'))->toBe("A LinkedIn reshare can't carry media.");
});

it('keeps dark and targeted posts for organization authors', function () {
    $options = new LinkedInOptions(feedDistribution: 'NONE', targetEntities: [['geoLocations' => ['urn:li:geo:1']]]);

    $person = Kit::driver()->validate(Kit::post(options: $options));
    $page = Kit::driver()->validate(Kit::post(options: $options, credentials: Kit::credentials(['author' => Kit::ORG])));
    $unknown = Kit::driver()->validate(Kit::post(options: $options, credentials: []));

    expect($person->has('feedDistribution'))->toBeTrue()
        ->and($person->has('targetEntities'))->toBeTrue()
        ->and($page->all())->toBe([])
        ->and($unknown->all())->toBe([]);
});

it('runs the deep media rules on inspected metadata', function (Media $media, MediaMetadata $meta, ?string $message) {
    $errors = Kit::driver(Kit::inspector($meta))->validate(Kit::post([$media]));

    $message === null
        ? expect($errors->all())->toBe([])
        : expect($errors->first('media.0'))->toContain($message);
})->with([
    'image too many pixels' => [fn () => Kit::image(), new MediaMetadata(width: 7000, height: 7000), 'pixels'],
    'image within pixels' => [fn () => Kit::image(), new MediaMetadata(width: 6000, height: 6000), null],
    'video under 75 KB' => [fn () => Media::video(Kit::file(10, 'mp4')), new MediaMetadata(sizeBytes: 50000, durationSeconds: 10), 'minimum file size'],
    'video over 500 MB' => [fn () => Media::video(Kit::file(10, 'mp4')), new MediaMetadata(sizeBytes: 600 * 1024 * 1024, durationSeconds: 10), 'maximum file size'],
    'video 300 MB is fine now' => [fn () => Media::video(Kit::file(10, 'mp4')), new MediaMetadata(sizeBytes: 300 * 1024 * 1024, durationSeconds: 10), null],
    'video too long' => [fn () => Media::video(Kit::file(10, 'mp4')), new MediaMetadata(sizeBytes: 100000, durationSeconds: 1801), 'too long'],
]);

it('allows 2 to 20 images in a carousel, images only', function () {
    $video = Kit::driver()->validate(Kit::post([Kit::image(), Media::video(Kit::file(10, 'mp4'))]));
    $many = Kit::driver()->validate(Kit::post(array_map(fn () => Kit::image(), range(1, 21))));

    expect($video->first('media.1'))->toContain('video media is not supported')
        ->and($many->first('media'))->toBe('Too many media items: maximum is 20, got 21.');
});

it('checks listed scopes per author type', function (string $author, string $scopes, ?string $missing) {
    $errors = Kit::driver()->validate(Kit::post(credentials: Kit::credentials(['author' => $author, 'scopes' => $scopes])));

    $missing === null
        ? expect($errors->has('credentials.scopes'))->toBeFalse()
        : expect($errors->first('credentials.scopes'))->toContain($missing);
})->with([
    'member ok' => [Kit::PERSON, 'openid profile w_member_social', null],
    'member missing' => [Kit::PERSON, 'openid profile', 'w_member_social'],
    'page ok' => [Kit::ORG, 'w_organization_social r_organization_social', null],
    'page with member scope only' => [Kit::ORG, 'w_member_social', 'w_organization_social'],
]);

it('publishes its scope catalogue', function () {
    $driver = Kit::driver();

    expect($driver->scopesFor(Feature::Publish))->toBe([['w_member_social', 'w_organization_social']])
        ->and($driver->scopesFor(Feature::Publish, new Credentials(['author' => Kit::ORG])))->toBe([['w_organization_social']])
        ->and($driver->scopesFor(Feature::Video, new Credentials(['author' => Kit::PERSON])))->toBe([])
        ->and($driver->scopesFor(Feature::Document))->toBe([]);
});

it('merges the options validation and ignores options meant for another platform', function () {
    $errors = Kit::driver()->validate(Kit::post(options: new LinkedInOptions(visibility: 'FRIENDS')));

    $other = new class implements PlatformOptions
    {
        public function platform(): Platform
        {
            return Platform::Threads;
        }

        public function extra(): array
        {
            return ['visibility' => 'CONNECTIONS', 'commentary' => 'hijack'];
        }

        public function validate(): MessageBag
        {
            return new MessageBag(['x' => 'not mine']);
        }
    };

    expect($errors->first('visibility'))->toBe('LinkedIn visibility must be one of PUBLIC, CONNECTIONS, LOGGED_IN.')
        ->and(Kit::driver()->validate(Kit::post(options: $other))->all())->toBe([]);

    $payload = Kit::driver()->call('buildPost', Kit::post(caption: 'Mine', options: $other), null);

    expect($payload['visibility'])->toBe('PUBLIC')
        ->and($payload['commentary'])->toBe('Mine');
});
