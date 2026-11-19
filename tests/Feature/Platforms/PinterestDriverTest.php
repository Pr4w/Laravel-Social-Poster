<?php

namespace SocialPoster\Tests\Feature\Platforms\PinterestDriverTest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Platforms\Pinterest\PinterestDriver;
use SocialPoster\Platforms\Pinterest\PinterestImageTransfer;
use SocialPoster\Platforms\Pinterest\PinterestOptions;
use SocialPoster\Tests\PinterestFixtures\PinterestKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;

require_once __DIR__.'/../../Fixtures/pinterest/PinterestKit.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    K::cleanupFiles();
});

/** Run publish() then resume() until a non-commit outcome (one create run). */
function publishAndCreate(PinterestDriver $driver, PreparedPost $post): mixed
{
    $commit = $driver->publish($post);

    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->recheckAfter)->toBe(0);

    return $driver->resume($post, $commit->state);
}

// --- Images --------------------------------------------------------------------------

it('pins one image by URL through a commit phase', function () {
    K::fake(['POST pins' => [K::json(K::body('pin_created'), 201)]]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);

    $commit = $driver->publish($post);

    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->recheckAfter)->toBe(0)
        ->and($commit->deadline)->toBeNull()
        ->and($commit->state)->toBe([
            'phase' => 'pin',
            'kind' => 'image',
            'transfer' => 'url',
            'since' => Carbon::now()->getTimestamp(),
        ]);

    Http::assertNothingSent();

    $published = $driver->resume($post, $commit->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platform)->toBe(Platform::Pinterest)
        ->and($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->url)->toBe('https://www.pinterest.com/pin/'.K::PIN_ID.'/')
        ->and($published->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($published->result->idKind)->toBe('pin_id')
        ->and($published->result->payload)->toMatchArray([
            'board_id' => K::BOARD,
            'kind' => 'image',
            'link' => 'https://example.com/spring',
            'transfer' => 'url',
        ])
        ->and($published->result->payload['response']['id'])->toBe(K::PIN_ID)
        ->and($published->result->payload)->not->toHaveKey('sandbox');

    $create = K::sent('POST pins')[0];

    expect($create->url())->toBe('https://api.pinterest.com/v5/pins')
        ->and($create->hasHeader('Authorization', 'Bearer '.K::TOKEN))->toBeTrue()
        ->and($create->data())->toBe([
            'board_id' => K::BOARD,
            'title' => 'Spring collection',
            'description' => 'New spring collection is live: https://example.com/spring.',
            'link' => 'https://example.com/spring',
            'media_source' => ['source_type' => 'image_url', 'url' => K::IMG_A],
        ]);
});

it('sends a local PNG as base64 sniffed from its bytes', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $png = K::file('png');
    $driver = K::driver();
    $post = K::post([Media::image($png, 'A red bicycle')], caption: '  Spring is here  ', title: null);

    $commit = $driver->publish($post);
    expect($commit->state['transfer'])->toBe('base64');

    $published = $driver->resume($post, $commit->state);

    expect($published->result->payload['transfer'])->toBe('base64')
        ->and($published->result->payload)->not->toHaveKey('link')
        ->and(K::sent('POST pins')[0]->data())->toBe([
            'board_id' => K::BOARD,
            'description' => 'Spring is here',
            'alt_text' => 'A red bicycle',
            'media_source' => [
                'source_type' => 'image_base64',
                'content_type' => 'image/png',
                'data' => base64_encode(K::PNG_BYTES),
            ],
        ]);

    // The base64 bytes never reach the queued state.
    expect(json_encode($commit->state))->not->toContain(base64_encode(K::PNG_BYTES));
});

it('sends a local JPEG as image/jpeg', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::file('jpg'))]));

    expect(K::sent('POST pins')[0]['media_source'])->toBe([
        'source_type' => 'image_base64',
        'content_type' => 'image/jpeg',
        'data' => base64_encode(K::JPEG_BYTES),
    ]);
});

it('refuses a .png whose bytes are WebP when sending base64', function () {
    K::fake([]);

    $fake = K::file('png', K::WEBP_BYTES);
    $driver = K::driver();
    $post = K::post([Media::image($fake)]);
    $commit = $driver->publish($post);

    $e = K::catch(fn () => $driver->resume($post, $commit->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('JPG or PNG');
    Http::assertNothingSent();
});

it('pins a carousel of URLs with the Pin link on every item', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $published = publishAndCreate(K::driver(), K::post([
        Media::image(K::IMG_A, 'First'),
        Media::image(K::IMG_B),
        Media::image(K::IMG_C),
    ]));

    expect($published->result->payload['kind'])->toBe('carousel')
        ->and(K::sent('POST pins')[0]->data())->toBe([
            'board_id' => K::BOARD,
            'title' => 'Spring collection',
            'description' => 'New spring collection is live: https://example.com/spring.',
            'link' => 'https://example.com/spring',
            'alt_text' => 'First',
            'media_source' => [
                'source_type' => 'multiple_image_urls',
                'items' => [
                    ['url' => K::IMG_A, 'link' => 'https://example.com/spring'],
                    ['url' => K::IMG_B, 'link' => 'https://example.com/spring'],
                    ['url' => K::IMG_C, 'link' => 'https://example.com/spring'],
                ],
            ],
        ]);
});

it('sends a whole carousel as base64 when one image has no URL', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $local = K::file('png');
    $downloaded = K::file('jpg');
    $gateway = K::rehostingGateway(downloads: [K::IMG_A => $downloaded]);

    $published = publishAndCreate(K::driver(gateway: $gateway), K::post([Media::image(K::IMG_A), Media::image($local)]));

    expect($published->result->payload['transfer'])->toBe('base64')
        ->and(K::sent('POST pins')[0]['media_source'])->toBe([
            'source_type' => 'multiple_image_base64',
            'items' => [
                ['content_type' => 'image/jpeg', 'data' => base64_encode(K::JPEG_BYTES), 'link' => 'https://example.com/spring'],
                ['content_type' => 'image/png', 'data' => base64_encode(K::PNG_BYTES), 'link' => 'https://example.com/spring'],
            ],
        ])
        ->and($gateway->cleanups)->toBeGreaterThanOrEqual(2);
});

it('applies per-item carousel overrides, extra fields and a deep-merged media_source', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $options = new PinterestOptions(
        carouselItems: [1 => ['title' => '  Item two  ', 'description' => 'Second', 'link' => 'https://example.com/two']],
        extra: [
            'dominant_color' => '#6E7874',
            'board_id' => '1',
            'title' => 'Never wins',
            'media_source' => ['index' => 1, 'source_type' => 'image_url', 'items' => [['title' => 'From extra']]],
        ],
    );

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A), Media::image(K::IMG_B)], options: $options));

    $data = K::sent('POST pins')[0]->data();

    expect($data['dominant_color'])->toBe('#6E7874')
        ->and($data['board_id'])->toBe(K::BOARD)
        ->and($data['title'])->toBe('Spring collection')
        ->and($data['media_source'])->toBe([
            'index' => 1,
            'source_type' => 'multiple_image_urls',
            'items' => [
                ['title' => 'From extra', 'url' => K::IMG_A, 'link' => 'https://example.com/spring'],
                ['url' => K::IMG_B, 'title' => 'Item two', 'description' => 'Second', 'link' => 'https://example.com/two'],
            ],
        ]);
});

it('ignores extra for another platform', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], options: new InstagramOptions(extra: ['dominant_color' => '#000000'])));

    expect(K::sent('POST pins')[0]->data())->not->toHaveKey('dominant_color');
});

it('takes the link, alt text and board section from the options', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $options = new PinterestOptions(
        link: ' https://shop.example.com/p/42 ',
        altText: ' Explicit alt ',
        boardSectionId: K::SECTION,
        adAccountId: '549755885175',
    );

    $published = publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A, 'Media alt')], options: $options, credentials: K::credentials(['board_section_id' => '111'])));

    $create = K::sent('POST pins')[0];

    expect($create->data())->toMatchArray([
        'board_section_id' => K::SECTION,
        'link' => 'https://shop.example.com/p/42',
        'alt_text' => 'Explicit alt',
    ])->and(K::query($create))->toBe(['ad_account_id' => '549755885175'])
        ->and($published->result->payload)->toMatchArray(['board_section_id' => K::SECTION, 'link' => 'https://shop.example.com/p/42']);
});

it('uses the credential board section and the account_id alias for the board', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], credentials: [
        'access_token' => K::TOKEN,
        'account_id' => K::BOARD,
        'board_section_id' => K::SECTION,
    ]));

    expect(K::sent('POST pins')[0]->data())->toMatchArray(['board_id' => K::BOARD, 'board_section_id' => K::SECTION]);
});

it('does not take a link from the caption when linkFromCaption is off', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A), Media::image(K::IMG_B)], options: new PinterestOptions(linkFromCaption: false)));

    $data = K::sent('POST pins')[0]->data();

    expect($data)->not->toHaveKey('link')
        ->and($data['media_source']['items'][0])->toBe(['url' => K::IMG_A]);
});

it('derives the link like production: trailing punctuation and an unbalanced parenthesis dropped', function (string $caption, ?string $link) {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], caption: $caption));

    expect(K::sent('POST pins')[0]['link'] ?? null)->toBe($link);
})->with([
    'sentence end' => ['Read https://example.com/a?b=1&c=2!', 'https://example.com/a?b=1&c=2'],
    'quoted' => ['Go "https://example.com/q", now', 'https://example.com/q'],
    'parenthesised' => ['Our shop (https://example.com/shop).', 'https://example.com/shop'],
    'balanced parentheses' => ['Wiki https://en.wikipedia.org/wiki/Pin_(disambiguation) ok', 'https://en.wikipedia.org/wiki/Pin_(disambiguation)'],
    'first of two' => ['https://one.example.com and https://two.example.com', 'https://one.example.com'],
    'no link' => ['Just words, www.example.com is not a link', null],
]);

it('drops a caption link longer than 2048 characters at publish time', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], caption: 'See https://example.com/'.str_repeat('a', 2100)));

    expect(K::sent('POST pins')[0]->data())->not->toHaveKey('link');
});

it('omits blank title and description', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], caption: '   ', title: '  '));

    expect(array_keys(K::sent('POST pins')[0]->data()))->toBe(['board_id', 'media_source']);
});

// --- Hosts and auth -----------------------------------------------------------------

it('sends every call to the Sandbox when config asks for it', function () {
    config(['social.api.pinterest.sandbox' => true]);
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $published = publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)]));

    expect(K::sent('POST pins')[0]->url())->toBe('https://api-sandbox.pinterest.com/v5/pins')
        ->and($published->result->payload['sandbox'])->toBeTrue();
});

it('lets the connection sandbox credential override the config', function (mixed $flag, bool $configSandbox, string $host) {
    config(['social.api.pinterest.sandbox' => $configSandbox]);
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], credentials: K::credentials(['sandbox' => $flag])));

    expect(parse_url(K::sent('POST pins')[0]->url(), PHP_URL_HOST))->toBe($host);
})->with([
    'true over false' => [true, false, 'api-sandbox.pinterest.com'],
    'string "true"' => ['true', false, 'api-sandbox.pinterest.com'],
    'false over true' => [false, true, 'api.pinterest.com'],
    'string "0" over true' => ['0', true, 'api.pinterest.com'],
    'unreadable falls back to config' => ['maybe', true, 'api-sandbox.pinterest.com'],
]);

it('honours a configured base URL and a Pinterest api_base credential', function () {
    config(['social.api.pinterest.base_url' => 'https://api.pinterest.com/v5/']);
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], credentials: K::credentials(['api_base' => 'https://api-sandbox.pinterest.com/v5/'])));

    expect(K::sent('POST pins')[0]->url())->toBe('https://api-sandbox.pinterest.com/v5/pins');
});

it('uses the configured authorizer instead of the bearer token', function () {
    $authorizer = new class implements AuthorizesRequests
    {
        public function request(Platform $platform, Credentials $credentials): PendingRequest
        {
            return Http::withToken('delegated-'.$credentials->get('connection'));
        }
    };

    config(['social.authorizers.pinterest' => $authorizer]);
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)], credentials: ['board_id' => K::BOARD, 'connection' => '7']));

    expect(K::sent('POST pins')[0]->hasHeader('Authorization', 'Bearer delegated-7'))->toBeTrue();
});

// --- Image transfer modes -------------------------------------------------------------

it('sends remote images as base64 when the transfer is forced', function (\Closure $configure) {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $downloaded = K::file('jpg');
    $gateway = K::rehostingGateway(downloads: [K::IMG_A => $downloaded]);

    $published = publishAndCreate(K::driver(gateway: $gateway), K::post([Media::image(K::IMG_A)], options: $configure()));

    expect($published->result->payload['transfer'])->toBe('base64')
        ->and(K::sent('POST pins')[0]['media_source']['source_type'])->toBe('image_base64');
})->with([
    'option' => [fn () => new PinterestOptions(imageTransfer: PinterestImageTransfer::Base64)],
    'config' => [function () {
        config(['social.api.pinterest.image_transfer' => 'BASE64']);

        return null;
    }],
]);

it('sends a re-hosted local image by URL', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/rehosted.png']);

    publishAndCreate(K::driver(gateway: $gateway), K::post([Media::image($local)]));

    expect(K::sent('POST pins')[0]['media_source'])->toBe(['source_type' => 'image_url', 'url' => 'https://media.example.com/rehosted.png']);
});

it('refuses a local image in Url mode before any request', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(K::file('png'))], options: new PinterestOptions(imageTransfer: PinterestImageTransfer::Url))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('by URL');
    Http::assertNothingSent();
});

it('refuses a local WebP that can only go as base64', function () {
    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(K::file('webp'))])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('JPG or PNG');
});

it('needs a gateway to send a remote image as base64', function () {
    $e = K::catch(fn () => K::driver(gateway: false)->publish(K::post([Media::image(K::IMG_A)], options: new PinterestOptions(imageTransfer: PinterestImageTransfer::Base64))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);
});

it('pins remote images by URL without a gateway', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    publishAndCreate(K::driver(gateway: false), K::post([Media::image(K::IMG_A)]));

    expect(K::sent('POST pins')[0]['media_source']['url'])->toBe(K::IMG_A);
});

// --- Auto base64 fallback ---------------------------------------------------------------

it('retries once with base64 when Pinterest cannot fetch the URL', function (\Closure $failure) {
    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/a.png']);

    K::fake(['POST pins' => [$failure, K::json(['id' => K::PIN_ID], 201)]]);

    $published = publishAndCreate(K::driver(gateway: $gateway), K::post([Media::image($local)]));

    $creates = K::sent('POST pins');

    expect($creates)->toHaveCount(2)
        ->and($creates[0]['media_source'])->toBe(['source_type' => 'image_url', 'url' => 'https://media.example.com/a.png'])
        ->and($creates[1]['media_source'])->toBe(['source_type' => 'image_base64', 'content_type' => 'image/png', 'data' => base64_encode(K::PNG_BYTES)])
        ->and($published->result->payload)->toMatchArray(['transfer' => 'base64', 'fell_back' => true]);
})->with([
    'could not fetch' => [K::error('fetch_failed', 400)],
    'code 2786' => [K::error('fetch_timeout_code', 400)],
]);

it('checks the board before re-sending a URL Pin as base64 after a 5xx, which may come after the Pin was saved', function (\Closure $failure) {
    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/a.png']);

    K::fake([
        'POST pins' => [$failure, K::json(['id' => K::PIN_ID], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_empty')],
    ]);

    $driver = K::driver(gateway: $gateway);
    $post = K::post([Media::image($local)]);
    $verify = publishAndCreate($driver, $post);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->commits)->toBeTrue()
        ->and($verify->recheckAfter)->toBe(60)
        ->and($verify->state)->toMatchArray(['phase' => 'verify_pin', 'transfer' => 'base64', 'fell_back' => true])
        ->and(K::sent('POST pins'))->toHaveCount(1);

    Carbon::setTestNow('2026-11-18 10:01:00');
    $published = $driver->resume($post, $verify->state);

    $creates = K::sent('POST pins');

    expect(K::routes())->toBe(['POST pins', 'GET boards/'.K::BOARD.'/pins', 'POST pins'])
        ->and($creates[0]['media_source'])->toBe(['source_type' => 'image_url', 'url' => 'https://media.example.com/a.png'])
        ->and($creates[1]['media_source'])->toBe(['source_type' => 'image_base64', 'content_type' => 'image/png', 'data' => base64_encode(K::PNG_BYTES)])
        ->and($published->result->payload)->toMatchArray(['transfer' => 'base64', 'fell_back' => true])
        ->and($published->result->payload)->not->toHaveKey('recovered');
})->with([
    '503 code 21 (create deadline)' => [K::error('service_unavailable', 503)],
    '500 code 12' => [K::error('internal_error', 500)],
    '502 html' => [K::json('<html>502 Bad Gateway</html>', 502)],
    '504 with code 2786' => [K::json(['code' => 2786, 'message' => 'Timed out.'], 504)],
]);

it('adopts the Pin a 5xx create saved instead of re-sending it as base64', function () {
    $downloaded = K::file('jpg');
    $gateway = K::rehostingGateway(downloads: [K::IMG_A => $downloaded]);

    K::fake([
        'POST pins' => [K::json('<html>502 Bad Gateway</html>', 502), K::json(['id' => '2'], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_match')],
    ]);

    $driver = K::driver(gateway: $gateway);
    $post = K::post([Media::image(K::IMG_A)]);
    $verify = publishAndCreate($driver, $post);

    Carbon::setTestNow('2026-11-18 10:01:00');
    $published = $driver->resume($post, $verify->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->payload['recovered'])->toBeTrue()
        ->and(K::routes())->toBe(['POST pins', 'GET boards/'.K::BOARD.'/pins']);
});

it('does not fall back above base64_max_bytes and surfaces the fetch error', function () {
    config(['social.api.pinterest.base64_max_bytes' => 10]);

    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/a.png']);

    K::fake(['POST pins' => [K::error('fetch_failed', 400)]]);

    $driver = K::driver(gateway: $gateway);
    $post = K::post([Media::image($local)]);
    $e = K::catch(fn () => publishAndCreate($driver, $post));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Timeout)
        ->and($e->retryAfter)->toBe(120)
        ->and($e->context['fetch_failed'])->toBeTrue()
        ->and(K::sent('POST pins'))->toHaveCount(1);
});

it('does not fall back for a WebP, in Url mode, or for an unrelated error', function (array $media, ?PinterestOptions $options, \Closure $failure) {
    K::fake(['POST pins' => [$failure]]);

    $e = K::catch(fn () => publishAndCreate(K::driver(), K::post($media, options: $options)));

    expect($e)->toBeInstanceOf(SocialPosterException::class)
        ->and(K::sent('POST pins'))->toHaveCount(1);
})->with([
    'webp' => [[Media::image('https://cdn.example.com/a.webp')], null, K::error('fetch_failed', 400)],
    'url mode' => [[Media::image(K::IMG_A)], new PinterestOptions(imageTransfer: PinterestImageTransfer::Url), K::error('service_unavailable', 503)],
    'invalid parameters' => [[Media::image(K::IMG_A)], null, K::error('invalid_parameters', 400)],
    'remote image the gateway cannot read' => [[Media::image(K::IMG_A)], null, K::error('fetch_failed', 400)],
]);

it('maps the error of the base64 retry when it fails too', function () {
    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/a.png']);

    K::fake(['POST pins' => [K::error('fetch_failed', 400), K::error('image_broken', 403)]]);

    $e = K::catch(fn () => publishAndCreate(K::driver(gateway: $gateway), K::post([Media::image($local)])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and(K::sent('POST pins'))->toHaveCount(2);
});

it('keeps base64 for the verify retry when the fallback create timed out', function () {
    $local = K::file('png');
    $gateway = K::rehostingGateway([$local => 'https://media.example.com/a.png']);

    K::fake([
        'POST pins' => [K::error('fetch_failed', 400), K::timeout(), K::json(['id' => K::PIN_ID], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_empty')],
    ]);

    $driver = K::driver(gateway: $gateway);
    $post = K::post([Media::image($local)]);
    $verify = publishAndCreate($driver, $post);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->state)->toMatchArray(['phase' => 'verify_pin', 'transfer' => 'base64', 'fell_back' => true]);

    $published = $driver->resume($post, $verify->state);

    expect(K::sent('POST pins')[2]['media_source']['source_type'])->toBe('image_base64')
        ->and($published->result->payload['fell_back'])->toBeTrue();
});

// --- Duplicate-safe create ---------------------------------------------------------------

it('verifies on the board after a create without answer, and adopts the Pin it finds', function () {
    K::fake([
        'POST pins' => [K::timeout()],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_match')],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $verify = publishAndCreate($driver, $post);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->commits)->toBeTrue()
        ->and($verify->recheckAfter)->toBe(60)
        ->and($verify->state)->toMatchArray([
            'phase' => 'verify_pin',
            'ambiguous_rounds' => 1,
            'kind' => 'image',
            'since' => Carbon::now()->getTimestamp(),
        ]);

    Carbon::setTestNow('2026-11-18 10:01:00');
    $published = $driver->resume($post, $verify->state + [Pending::RECOVERING => true, Pending::ATTEMPT => 1]);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->payload['recovered'])->toBeTrue()
        ->and(K::sent('POST pins'))->toHaveCount(1)
        ->and(K::query(K::sent('GET boards/'.K::BOARD.'/pins')[0]))->toBe(['page_size' => '50']);
});

it('gives every verify step its own deadline', function () {
    K::fake([
        'POST pins' => [K::timeout()],
        'GET boards/'.K::BOARD.'/pins' => [K::error('service_unavailable', 503)],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $verify = publishAndCreate($driver, $post);

    // verify_retries 5, verify_after 60 and pin_timeout 60: 6 × 120 s + 300 s.
    expect($verify->deadline)->toBe(Carbon::now()->getTimestamp() + 1020);

    Carbon::setTestNow('2026-11-18 10:01:00');
    $again = $driver->resume($post, $verify->state);

    expect($again)->toBeInstanceOf(Pending::class)
        ->and($again->state['verify_failures'])->toBe(1)
        ->and($again->deadline)->toBe(Carbon::now()->getTimestamp() + 1020);
});

it('flags a failure before the verify lookup as possibly published', function () {
    K::fake([]);

    $post = K::post([Media::image(K::IMG_A)], credentials: K::credentials(['expires_at' => Carbon::now()->subMinute()->getTimestamp()]));

    $verify = K::catch(fn () => K::driver()->resume($post, ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => 1]));
    $create = K::catch(fn () => K::driver()->resume($post, ['phase' => 'pin', 'kind' => 'image', 'transfer' => 'url', 'since' => 1]));

    expect($verify)->toBeInstanceOf(PermanentException::class)
        ->and($verify->reason)->toBe(FailureReason::InvalidToken)
        ->and($verify->possiblyPublished())->toBeTrue()
        ->and($create->reason)->toBe(FailureReason::InvalidToken)
        ->and($create->context)->not->toHaveKey('possibly_published');
    Http::assertNothingSent();
});

it('compares the title and description actually sent, extra fields included', function () {
    K::fake([
        'POST pins' => [K::timeout(), K::json(['id' => '2'], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::json(['items' => [
            ['id' => K::PIN_ID, 'title' => 'Pinterest only title', 'description' => 'Pinterest only description', 'created_at' => '2026-11-18T10:00:01'],
        ]])],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)], caption: null, title: null, options: new PinterestOptions(extra: [
        'title' => 'Pinterest only title',
        'description' => "Pinterest only description\n",
    ]));

    $verify = publishAndCreate($driver, $post);

    expect(K::sent('POST pins')[0]->data())->toMatchArray(['title' => 'Pinterest only title', 'description' => "Pinterest only description\n"]);

    Carbon::setTestNow('2026-11-18 10:01:00');
    $published = $driver->resume($post, $verify->state);

    expect($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->payload['recovered'])->toBeTrue()
        ->and(K::routes())->toBe(['POST pins', 'GET boards/'.K::BOARD.'/pins']);
});

it('creates once more when the verify lookup finds nothing', function () {
    K::fake([
        'POST pins' => [K::timeout(), K::json(['id' => K::PIN_ID], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_no_match')],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $verify = publishAndCreate($driver, $post);
    $published = $driver->resume($post, $verify->state);

    expect($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->payload)->not->toHaveKey('recovered')
        ->and(K::routes())->toBe(['POST pins', 'GET boards/'.K::BOARD.'/pins', 'POST pins']);
});

it('verifies first when the job flags a recovering create', function (string $fixture, array $routes) {
    K::fake([
        'POST pins' => [K::json(['id' => K::PIN_ID], 201)],
        'GET boards/'.K::BOARD.'/pins' => [K::body($fixture)],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $commit = $driver->publish($post);

    $published = $driver->resume($post, $commit->state + [Pending::RECOVERING => true, Pending::COMMITTING_SINCE => 1]);

    expect($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and(K::routes())->toBe($routes);
})->with([
    'found' => ['board_pins_match', ['GET boards/'.K::BOARD.'/pins']],
    'not found' => ['board_pins_empty', ['GET boards/'.K::BOARD.'/pins', 'POST pins']],
]);

it('anchors the verify window on the job commit time when the state has none', function () {
    K::fake(['GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_match')]]);

    $published = K::driver()->resume(K::post([Media::image(K::IMG_A)]), [
        'phase' => 'pin',
        'kind' => 'image',
        'transfer' => 'url',
        Pending::RECOVERING => true,
        Pending::COMMITTING_SINCE => Carbon::parse('2026-11-17 08:12:00')->getTimestamp(),
    ]);

    // The older identical Pin is inside this wider window and comes first.
    expect($published->result->platformPostId)->toBe('813744226420795001');
});

it('looks up the board section when the Pin targets one', function () {
    K::fake([
        'GET boards/'.K::BOARD.'/sections/'.K::SECTION.'/pins' => [K::body('board_pins_match')],
    ]);

    $post = K::post([Media::image(K::IMG_A)], options: new PinterestOptions(adAccountId: '549755885175'), credentials: K::credentials(['board_section_id' => K::SECTION]));
    $published = K::driver()->resume($post, ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => Carbon::now()->getTimestamp()]);

    expect($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and(K::query(K::sent('GET boards/'.K::BOARD.'/sections/'.K::SECTION.'/pins')[0]))->toBe(['page_size' => '50', 'ad_account_id' => '549755885175']);
});

it('skips unreadable listing items and adopts a match whose creation time is unknown', function () {
    K::fake(['GET boards/'.K::BOARD.'/pins' => [K::json(['items' => [
        'not an item',
        ['title' => 'Spring collection', 'description' => 'New spring collection is live: https://example.com/spring.', 'created_at' => '2026-11-18T10:00:05'],
        ['id' => '', 'title' => 'Spring collection', 'description' => 'New spring collection is live: https://example.com/spring.'],
        ['id' => 813744226420795999, 'title' => "Spring collection\r\n", 'description' => "New spring collection is live: https://example.com/spring.\r\n", 'created_at' => 'not a date'],
    ]])]]);

    $published = K::driver()->resume(K::post([Media::image(K::IMG_A)]), ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => Carbon::now()->getTimestamp()]);

    expect($published->result->platformPostId)->toBe('813744226420795999')
        ->and($published->result->payload['recovered'])->toBeTrue();
});

it('falls back to Auto for an unknown image_transfer setting', function () {
    config(['social.api.pinterest.image_transfer' => 'carrier-pigeon']);

    $commit = K::driver()->publish(K::post([Media::image(K::IMG_A)]));

    expect($commit->state['transfer'])->toBe('url');
});

it('cannot tell an untitled Pin apart from recent ones', function () {
    K::fake(['GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_no_match')]]);

    $e = K::catch(fn () => K::driver()->resume(
        K::post([Media::image(K::IMG_A)], caption: null, title: null),
        ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => Carbon::parse('2026-11-18 09:59:50')->getTimestamp()],
    ));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context['candidates'])->toBe(1);
});

it('creates an untitled Pin when nothing was saved to the board since', function () {
    K::fake([
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_no_match')],
        'POST pins' => [K::json(['id' => K::PIN_ID], 201)],
    ]);

    $published = K::driver()->resume(
        K::post([Media::image(K::IMG_A)], caption: null, title: null),
        ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => Carbon::parse('2026-11-18 10:05:00')->getTimestamp()],
    );

    expect($published->result->platformPostId)->toBe(K::PIN_ID);
});

it('rethrows a transport failure that never left, for a plain retry', function () {
    K::fake(['POST pins' => [K::neverSent()]]);

    $e = K::catch(fn () => publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)])));

    expect($e)->toBeInstanceOf(ConnectionException::class);
});

it('reports OutcomeUnknown instead of verifying when dedupe_on_timeout is off', function () {
    config(['social.api.pinterest.dedupe_on_timeout' => false]);
    K::fake(['POST pins' => [K::timeout()]]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);

    $e = K::catch(fn () => publishAndCreate($driver, $post));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);

    $recovering = K::catch(fn () => $driver->resume($post, ['phase' => 'pin', 'kind' => 'image', 'transfer' => 'url', Pending::RECOVERING => true]));

    expect($recovering)->toBeInstanceOf(PermanentException::class)
        ->and($recovering->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and(K::sent('POST pins'))->toHaveCount(1);
});

it('gives up after verify_retries creates without answer', function () {
    config(['social.api.pinterest.verify_retries' => 1]);
    K::fake([
        'POST pins' => [K::timeout()],
        'GET boards/'.K::BOARD.'/pins' => [K::body('board_pins_empty')],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $verify = publishAndCreate($driver, $post);

    $e = K::catch(fn () => $driver->resume($post, $verify->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->context['ambiguous_rounds'])->toBe(2);
});

it('retries an unreadable lookup later, then reports OutcomeUnknown', function (\Closure $failure) {
    config(['social.api.pinterest.verify_retries' => 1, 'social.api.pinterest.verify_after' => 45]);
    K::fake(['GET boards/'.K::BOARD.'/pins' => [$failure]]);

    $driver = K::driver();
    $post = K::post([Media::image(K::IMG_A)]);
    $state = ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => Carbon::now()->getTimestamp()];

    $again = $driver->resume($post, $state);

    expect($again)->toBeInstanceOf(Pending::class)
        ->and($again->commits)->toBeTrue()
        ->and($again->recheckAfter)->toBe(45)
        ->and($again->state)->toMatchArray(['phase' => 'verify_pin', 'verify_failures' => 1]);

    $e = K::catch(fn () => $driver->resume($post, $again->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
    expect(K::sent('POST pins'))->toBe([]);
})->with([
    '503' => [K::error('service_unavailable', 503)],
    '429' => [K::error('rate_limited', 429)],
    'timeout' => [K::timeout()],
    'not a listing' => [K::json(['unexpected' => true])],
]);

it('flags a refused lookup as possibly published', function () {
    K::fake(['GET boards/'.K::BOARD.'/pins' => [K::error('unauthorized', 401)]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::image(K::IMG_A)]), ['phase' => 'verify_pin', 'kind' => 'image', 'transfer' => 'url', 'since' => 1]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context['step'])->toBe('lookup');
});

it('treats a 2xx create without an id as a possibly published outcome', function () {
    K::fake(['POST pins' => [K::json(['board_id' => K::BOARD], 201)]]);

    $e = K::catch(fn () => publishAndCreate(K::driver(), K::post([Media::image(K::IMG_A)])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

// --- Video ----------------------------------------------------------------------------

it('registers, uploads to S3 without the token, polls and pins a video', function () {
    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Ok()],
        'GET media/'.K::MEDIA_ID => [K::body('media_status_registered'), K::body('media_status_processing'), K::body('media_status_succeeded')],
        'POST pins' => [K::json(['id' => K::PIN_ID], 201)],
    ]);

    $video = K::file('mp4', str_repeat('v', 2048));
    $driver = K::driver();
    $post = K::post([Media::video($video, null, 'A short clip')]);

    $pending = $driver->publish($post);
    $now = Carbon::now()->getTimestamp();

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->committed)->toBeFalse()
        ->and($pending->recheckAfter)->toBe(10)
        ->and($pending->deadline)->toBe($now + 1800)
        ->and($pending->state)->toBe([
            'phase' => 'await_media',
            'kind' => 'video',
            'media_id' => K::MEDIA_ID,
            'polls' => 0,
            'deadline' => $now + 1800,
        ]);

    $register = K::sent('POST media')[0];
    expect($register->data())->toBe(['media_type' => 'video'])
        ->and($register->hasHeader('Authorization', 'Bearer '.K::TOKEN))->toBeTrue();

    $upload = K::sent('POST s3')[0];
    $parts = $upload->data();
    $names = array_column($parts, 'name');

    expect($upload->url())->toBe(K::S3)
        ->and($upload->hasHeader('Authorization'))->toBeFalse()
        ->and($upload->isMultipart())->toBeTrue()
        ->and(end($names))->toBe('file')
        ->and(array_slice($names, 0, -1))->toBe(array_keys(K::body('media_registered')['upload_parameters']))
        ->and($parts[array_search('Content-Type', $names, true)]['contents'])->toBe('multipart/form-data')
        ->and($parts[count($parts) - 1]['filename'])->toBe(basename($video));

    $registered = $driver->resume($post, $pending->state);
    expect($registered)->toBeInstanceOf(Pending::class)
        ->and($registered->recheckAfter)->toBe(10)
        ->and($registered->state)->toMatchArray(['polls' => 1, 'media_status' => 'registered']);

    $processing = $driver->resume($post, $registered->state);
    expect($processing->state['polls'])->toBe(2);

    $commit = $driver->resume($post, $processing->state);
    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->recheckAfter)->toBe(0)
        ->and($commit->state)->toBe(['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => $now]);

    $published = $driver->resume($post, $commit->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published->result->payload)->toMatchArray([
            'kind' => 'video',
            'media_id' => K::MEDIA_ID,
            'transfer' => 'upload',
            'cover' => 'key_frame',
        ])
        ->and(K::sent('POST pins')[0]->data())->toBe([
            'board_id' => K::BOARD,
            'title' => 'Spring collection',
            'description' => 'New spring collection is live: https://example.com/spring.',
            'link' => 'https://example.com/spring',
            'alt_text' => 'A short clip',
            'media_source' => [
                'source_type' => 'video_id',
                'media_id' => K::MEDIA_ID,
                'cover_image_key_frame_time' => 0,
            ],
        ]);
});

it('polls every 10 seconds six times, then every 30', function (int $polls, int $after) {
    K::fake(['GET media/'.K::MEDIA_ID => [K::body('media_status_processing')]]);

    $next = K::driver()->resume(K::post([Media::video(K::file('mp4'))]), [
        'phase' => 'await_media', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'polls' => $polls, 'deadline' => Carbon::now()->getTimestamp() + 600,
    ]);

    expect($next->recheckAfter)->toBe($after)
        ->and($next->deadline)->toBe(Carbon::now()->getTimestamp() + 600)
        ->and($next->state['polls'])->toBe($polls + 1);
})->with([[0, 10], [5, 10], [6, 30], [40, 30]]);

it('waits 60 seconds on a throttled or failing status check without failing', function (\Closure $failure, int $after) {
    K::fake(['GET media/'.K::MEDIA_ID => [$failure]]);

    $next = K::driver()->resume(K::post([Media::video(K::file('mp4'))]), [
        'phase' => 'await_media', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'polls' => 3,
    ]);

    expect($next)->toBeInstanceOf(Pending::class)
        ->and($next->commits)->toBeFalse()
        ->and($next->recheckAfter)->toBe($after)
        ->and($next->state['polls'])->toBe(4);
})->with([
    '429' => [K::error('rate_limited', 429), 60],
    '429 with Retry-After' => [K::error('rate_limited', 429, ['Retry-After' => '240']), 240],
    // x-ratelimit-reset is a quota window, not a wait.
    '429 with only x-ratelimit-reset' => [K::error('rate_limited', 429, ['x-ratelimit-reset' => '800']), 60],
    '503' => [K::error('service_unavailable', 503), 60],
]);

it('fails a video Pinterest could not process', function () {
    K::fake(['GET media/'.K::MEDIA_ID => [K::body('media_status_failed')]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), ['phase' => 'await_media', 'media_id' => K::MEDIA_ID]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('MP4 (H.264)')
        ->and($e->context['media_id'])->toBe(K::MEDIA_ID)
        ->and($e->context['media']['status'])->toBe('failed');
});

it('stops polling once the video deadline passed', function (\Closure $response) {
    K::fake(['GET media/'.K::MEDIA_ID => [$response]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), [
        'phase' => 'await_media', 'media_id' => K::MEDIA_ID, 'polls' => 50, 'deadline' => Carbon::now()->getTimestamp() - 1, 'media_status' => 'processing',
    ]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::ProcessingTimeout)
        ->and($e->possiblyPublished())->toBeFalse();
})->with([
    'processing' => [K::json(K::body('media_status_processing'))],
    'throttled' => [K::error('rate_limited', 429)],
]);

it('gives the create after a processed video its own deadline, never the processing one', function () {
    K::fake(['GET media/'.K::MEDIA_ID => [K::body('media_status_succeeded')]]);

    $now = Carbon::now()->getTimestamp();

    // The last look, exactly at the processing deadline.
    $commit = K::driver()->resume(K::post([Media::video(K::file('mp4'))]), [
        'phase' => 'await_media', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'polls' => 9, 'deadline' => $now, 'media_status' => 'processing',
    ]);

    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->state)->toBe(['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => $now])
        ->and($commit->deadline)->toBe($now + 1020);
});

it('uses the video thumbnail URL as the cover', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $published = K::driver()->resume(
        K::post([Media::video(K::file('mp4'), 'https://cdn.example.com/cover.jpg')]),
        ['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => 1],
    );

    expect(K::sent('POST pins')[0]['media_source'])->toBe([
        'source_type' => 'video_id',
        'media_id' => K::MEDIA_ID,
        'cover_image_url' => 'https://cdn.example.com/cover.jpg',
    ])->and($published->result->payload['cover'])->toBe('url');
});

it('sends a local cover image as base64 and never keeps it in the state', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $cover = K::file('png');
    $published = K::driver()->resume(
        K::post([Media::video(K::file('mp4'))], options: new PinterestOptions(thumbnail: Media::image($cover))),
        ['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => 1],
    );

    expect(K::sent('POST pins')[0]['media_source'])->toBe([
        'source_type' => 'video_id',
        'media_id' => K::MEDIA_ID,
        'cover_image_content_type' => 'image/png',
        'cover_image_data' => base64_encode(K::PNG_BYTES),
    ])->and($published->result->payload['cover'])->toBe('base64');
});

it('uses the configured key frame, and falls back to it for a cover it cannot send', function (?PinterestOptions $options, ?string $thumbnail, int $frame) {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    K::driver()->resume(
        K::post([Media::video(K::file('mp4'), $thumbnail)], options: $options),
        ['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => 1],
    );

    expect(K::sent('POST pins')[0]['media_source']['cover_image_key_frame_time'])->toBe($frame);
})->with(function () {
    return [
        'key frame option' => [new PinterestOptions(coverKeyFrameTime: 3), null, 3],
        'local webp thumbnail' => [null, sys_get_temp_dir().'/sp-pin-cover.webp', 0],
    ];
});

it('starts again from the registration when S3 refuses the expired policy', function () {
    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Error('s3_access_denied', 403)],
    ]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::video(K::file('mp4'))])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->retryAfter)->toBe(120)
        ->and($e->context)->toMatchArray(['s3_code' => 'AccessDenied', 'step' => 'upload', 'http_status' => 403]);
});

it('classifies the other S3 errors', function (\Closure $failure, string $class, FailureReason $reason) {
    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [$failure],
    ]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::video(K::file('mp4'))])));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason);
})->with([
    'entity too large' => [K::s3Error('s3_entity_too_large', 400), PermanentException::class, FailureReason::MediaRejected],
    // A short body is a transfer hiccup, not a bad file: register and upload again.
    'incomplete body' => [K::s3Error('s3_incomplete_body', 400), TemporaryException::class, FailureReason::ServerError],
    'invalid argument' => [K::s3Error('s3_invalid_argument', 400), PermanentException::class, FailureReason::Unknown],
    'bare 500' => [K::json('', 500), TemporaryException::class, FailureReason::ServerError],
]);

it('retries a registration that came back without an upload URL', function () {
    K::fake(['POST media' => [K::body('media_registered_without_url')]]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::video(K::file('mp4'))])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['missing_field'])->toBe('upload_url');
    expect(K::sent('POST s3'))->toBe([]);
});

it('maps a refused registration', function () {
    K::fake(['POST media' => [K::error('unauthorized', 401)]]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::video(K::file('mp4'))])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['step'])->toBe('media_register');
});

it('needs a gateway to upload a remote video', function () {
    K::fake(['POST media' => [K::body('media_registered')]]);

    $e = K::catch(fn () => K::driver(gateway: false)->publish(K::post([Media::video('https://cdn.example.com/clip.mp4')])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);
});

it('names the S3 file with a video extension', function () {
    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Ok()],
    ]);

    $odd = K::file('mov');
    $gateway = K::rehostingGateway(downloads: ['https://cdn.example.com/stream' => $odd]);

    K::driver(gateway: $gateway)->publish(K::post([new Media('https://cdn.example.com/stream', MediaType::Video)]));

    $parts = K::sent('POST s3')[0]->data();

    expect($parts[count($parts) - 1]['filename'])->toBe('video.mp4');
});

it('maps a 401 on a video Pin to a missing cover when the token still works', function () {
    K::fake([
        'POST pins' => [K::error('unauthorized', 401)],
        'GET media/'.K::MEDIA_ID => [K::body('media_status_succeeded')],
    ]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), ['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => 1]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['cover_required'])->toBeTrue()
        ->and($e->getMessage())->toContain('cover');
});

it('keeps a 401 on a video Pin as InvalidToken when the token is dead', function () {
    K::fake([
        'POST pins' => [K::error('unauthorized', 401)],
        'GET media/'.K::MEDIA_ID => [K::error('unauthorized', 401)],
    ]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), ['phase' => 'pin', 'kind' => 'video', 'media_id' => K::MEDIA_ID, 'since' => 1]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken);
});

it('fails an upload Pinterest no longer knows', function () {
    K::fake(['GET media/'.K::MEDIA_ID => [K::error('media_not_found', 404)]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), ['phase' => 'await_media', 'media_id' => K::MEDIA_ID]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['upload_not_found'])->toBeTrue();
});

// --- Continuations -------------------------------------------------------------------------

it('refuses an unknown phase and a continuation without its media id', function (array $state, string $message) {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(K::file('mp4'))]), $state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Unknown)
        ->and($e->getMessage())->toContain($message);
})->with([
    'unknown phase' => [['phase' => 'publish_retry'], "Unknown Pinterest publish phase 'publish_retry'"],
    'no phase' => [[], "Unknown Pinterest publish phase ''"],
    'await without media id' => [['phase' => 'await_media'], 'no media_id'],
    'video pin without media id' => [['phase' => 'pin', 'kind' => 'video'], 'no media_id'],
]);

it('derives the kind when the state lost it', function () {
    K::fake(['POST pins' => [K::json(['id' => K::PIN_ID], 201)]]);

    $published = K::driver()->resume(K::post([Media::image(K::IMG_A), Media::image(K::IMG_B)]), ['phase' => 'pin']);

    expect($published->result->payload)->toMatchArray(['kind' => 'carousel', 'transfer' => 'url'])
        ->and(K::sent('POST pins')[0]['media_source']['source_type'])->toBe('multiple_image_urls');
});

it('caps the S3 timeout below the job timeout', function (array $config, int $expected) {
    config($config);

    $driver = new class(null, null) extends PinterestDriver
    {
        public function exposedUploadTimeout(): int
        {
            return $this->uploadTimeout();
        }
    };

    expect($driver->exposedUploadTimeout())->toBe($expected);
})->with([
    'global default' => [['social.api.pinterest.upload_timeout' => null, 'social.job_timeout' => null], 300],
    'global' => [['social.api.pinterest.upload_timeout' => null, 'social.upload_timeout' => 500, 'social.job_timeout' => null], 500],
    'pinterest, job timeout derived' => [['social.api.pinterest.upload_timeout' => 600, 'social.job_timeout' => null], 600],
    'pinterest above an explicit job timeout' => [['social.api.pinterest.upload_timeout' => 600, 'social.job_timeout' => 420], 360],
    'job raised' => [['social.api.pinterest.upload_timeout' => 600, 'social.job_timeout' => '900'], 600],
    'job unlimited' => [['social.api.pinterest.upload_timeout' => 600, 'social.job_timeout' => 0], 600],
    'floor' => [['social.api.pinterest.upload_timeout' => 1, 'social.job_timeout' => null], 30],
]);
