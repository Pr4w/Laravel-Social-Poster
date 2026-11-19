<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\LinkedIn\LinkedInArticle;
use SocialPoster\Platforms\LinkedIn\LinkedInMention;
use SocialPoster\Platforms\LinkedIn\LinkedInMentionResolver;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Platforms\LinkedIn\LinkedInPoll;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;

// --- LinkedInOptions --------------------------------------------------------------------

it('keeps the v1.0 constructor positions and defaults', function () {
    $options = new LinkedInOptions(false, ['contentLandingPage' => 'https://x'], [['urn' => Kit::ORG, 'name' => 'Acme']]);

    expect($options->escapeText)->toBeFalse()
        ->and($options->extra())->toBe(['contentLandingPage' => 'https://x'])
        ->and($options->mentions[0])->toBeInstanceOf(LinkedInMention::class)
        ->and($options->mentions[0]->name)->toBe('Acme')
        ->and($options->platform())->toBe(Platform::LinkedIn)
        ->and($options->visibility)->toBe('PUBLIC')
        ->and($options->feedDistribution)->toBe('MAIN_FEED')
        ->and($options->disableReshare)->toBeFalse()
        ->and($options->targetEntities)->toBe([])
        ->and($options->reshareOf)->toBeNull()
        ->and($options->poll)->toBeNull()
        ->and($options->article)->toBeNull()
        ->and($options->autoLinkCard)->toBeFalse()
        ->and($options->autoMentionUrls)->toBeFalse()
        ->and($options->validate()->all())->toBe([]);
});

it('restores options queued by v1.0 with the new fields defaulted', function () {
    $mention = serialize(new LinkedInMention(Kit::ORG, 'Acme'));
    $class = LinkedInOptions::class;
    // Exactly what v1.0 wrote: only escapeText, extra and mentions.
    $legacy = 'O:'.strlen($class).':"'.$class.'":3:{s:10:"escapeText";b:0;s:5:"extra";a:1:{s:1:"k";s:1:"v";}s:8:"mentions";a:1:{i:0;'.$mention.'}}';

    $options = unserialize($legacy);

    expect($options)->toBeInstanceOf(LinkedInOptions::class)
        ->and($options->escapeText)->toBeFalse()
        ->and($options->extra())->toBe(['k' => 'v'])
        ->and($options->mentions[0]->urn)->toBe(Kit::ORG)
        ->and($options->visibility)->toBe('PUBLIC')
        ->and($options->feedDistribution)->toBe('MAIN_FEED')
        ->and($options->poll)->toBeNull()
        ->and($options->article)->toBeNull()
        ->and($options->autoMentionUrls)->toBeFalse()
        ->and($options->validate()->all())->toBe([]);

    $payload = Kit::driver()->call('buildPost', Kit::post(caption: 'Hi {{mention:0}}', options: $options), null);

    expect($payload['commentary'])->toBe('Hi @[Acme]('.Kit::ORG.')')
        ->and($payload['visibility'])->toBe('PUBLIC')
        ->and($payload['k'])->toBe('v');
});

it('round-trips the 1.1 options through serialization', function () {
    $options = new LinkedInOptions(
        visibility: 'CONNECTIONS',
        disableReshare: true,
        reshareOf: ' '.Kit::SHARE.' ',
        poll: LinkedInPoll::make('Q?', ['A', 'B'], LinkedInPoll::ONE_DAY),
        article: LinkedInArticle::make('https://example.com', 'T'),
        autoLinkCard: true,
        autoMentionUrls: true,
    );

    $copy = unserialize(serialize($options));

    expect($copy->visibility)->toBe('CONNECTIONS')
        ->and($copy->disableReshare)->toBeTrue()
        ->and($copy->reshareOf)->toBe(Kit::SHARE)
        ->and($copy->poll->duration)->toBe('ONE_DAY')
        ->and($copy->article->source)->toBe('https://example.com')
        ->and($copy->autoLinkCard)->toBeTrue()
        ->and($copy->autoMentionUrls)->toBeTrue();
});

it('validates the typed options', function (LinkedInOptions $options, string $key, string $message) {
    $errors = $options->validate();

    expect($errors->get($key))->toContain($message);
})->with([
    'visibility' => [fn () => new LinkedInOptions(visibility: 'FRIENDS'), 'visibility', 'LinkedIn visibility must be one of PUBLIC, CONNECTIONS, LOGGED_IN.'],
    'feed distribution' => [fn () => new LinkedInOptions(feedDistribution: 'SIDEBAR'), 'feedDistribution', 'LinkedIn feedDistribution must be MAIN_FEED or NONE.'],
    'reshare urn' => [fn () => new LinkedInOptions(reshareOf: 'urn:li:activity:1'), 'reshareOf', 'reshareOf must be a post URN (urn:li:share:… or urn:li:ugcPost:…).'],
    'target entity' => [fn () => new LinkedInOptions(targetEntities: ['geo']), 'targetEntities', 'Target entity #0 must be a non-empty array of targeting facets.'],
    'mention urn' => [fn () => new LinkedInOptions(mentions: [['urn' => 'urn:li:organization:123) x', 'name' => 'Acme']]), 'mentions.0', 'Mention #0 has an invalid URN; expected urn:li:person:… or urn:li:organization:….'],
    'mention name' => [fn () => new LinkedInOptions(mentions: [['urn' => Kit::ORG, 'name' => ' ']]), 'mentions.0', 'Mention #0 is missing a display name.'],
    'poll' => [fn () => new LinkedInOptions(poll: ['question' => '', 'options' => ['A']]), 'poll', 'The poll needs a question.'],
    'article' => [fn () => new LinkedInOptions(article: ['source' => 'ftp://x', 'title' => 'T']), 'article', 'The link card source must be an http(s) URL.'],
    'two content kinds' => [fn () => new LinkedInOptions(reshareOf: Kit::SHARE, poll: ['question' => 'Q?', 'options' => ['A', 'B']]), 'reshareOf', 'A LinkedIn post takes only one of a link card (article), a poll or a reshare; got poll and reshareOf.'],
]);

it('tightens and trims mention URNs', function () {
    expect(LinkedInMention::make('  urn:li:person:Jd0e_X-1 ', 'Jane')->urn)->toBe('urn:li:person:Jd0e_X-1')
        ->and(LinkedInMention::make('  urn:li:person:Jd0e_X-1 ', 'Jane')->hasValidUrn())->toBeTrue()
        ->and(LinkedInMention::make('urn:li:organization:2414183', 'Acme')->hasValidUrn())->toBeTrue()
        ->and(LinkedInMention::make("urn:li:person:a\nb", 'x')->hasValidUrn())->toBeFalse()
        ->and(LinkedInMention::make('urn:li:organization:1) x', 'x')->hasValidUrn())->toBeFalse()
        ->and(LinkedInMention::make('urn:li:company:1', 'x')->hasValidUrn())->toBeFalse();
});

// --- LinkedInPoll / LinkedInArticle --------------------------------------------------------

it('validates polls against the documented limits', function () {
    $errors = LinkedInPoll::make(str_repeat('q', 141), ['A', 'a', str_repeat('o', 31), '', 'E'], 'TWO_WEEKS')->errors();

    expect($errors)->toBe([
        'The poll question exceeds 140 characters.',
        'A poll needs 2 to 4 options, got 5.',
        'Poll option #3 exceeds 30 characters.',
        'Poll option #4 is empty.',
        'Poll options must be different from each other.',
        'The poll duration must be one of ONE_DAY, THREE_DAYS, SEVEN_DAYS, FOURTEEN_DAYS.',
    ])->and(LinkedInPoll::fromArray(['question' => 'Q?', 'options' => ['Yes', 'No']])->errors())->toBe([])
        ->and(LinkedInPoll::fromArray(['options' => 'nope'])->options)->toBe([]);
});

it('builds article content and validates it', function () {
    $article = LinkedInArticle::fromArray(['url' => ' https://example.com/a ', 'title' => ' Title ', 'description' => '', 'thumbnail_alt_text' => 'Alt']);

    expect($article->source)->toBe('https://example.com/a')
        ->and($article->toContent())->toBe(['article' => ['source' => 'https://example.com/a', 'title' => 'Title']])
        ->and($article->toContent('urn:li:image:X'))->toBe(['article' => ['source' => 'https://example.com/a', 'title' => 'Title', 'thumbnail' => 'urn:li:image:X', 'thumbnailAltText' => 'Alt']])
        ->and($article->errors())->toBe([])
        ->and(LinkedInArticle::make('', null, null, null, str_repeat('x', 4087))->errors())->toBe([
            'The link card needs a source URL.',
            'The link card needs a title (LinkedIn does not read it from the page).',
            'The link card thumbnail alt text exceeds 4086 characters.',
        ]);
});

// --- LinkedInMentionResolver ---------------------------------------------------------------

it('recognises LinkedIn profile, company and school URLs', function (string $url, ?array $expected) {
    expect(LinkedInMentionResolver::parseUrl($url))->toBe($expected);
})->with([
    'profile' => ['https://www.linkedin.com/in/jane-doe/', ['type' => 'person', 'vanity' => 'jane-doe']],
    'bare company with query' => ['linkedin.com/company/acme?trk=feed', ['type' => 'organization', 'vanity' => 'acme']],
    'localised school' => ['https://fr.linkedin.com/school/mit/', ['type' => 'organization', 'vanity' => 'mit']],
    'encoded vanity' => ['https://www.linkedin.com/in/caf%C3%A9-ol%C3%A9', ['type' => 'person', 'vanity' => 'café-olé']],
    'feed' => ['https://www.linkedin.com/feed/', null],
    'other site' => ['https://example.com/in/jane', null],
    'empty vanity' => ['https://www.linkedin.com/in/', null],
]);

it('resolves an organization by vanity name', function () {
    Http::fake(['api.linkedin.com/v2/organizations*' => Kit::fixture('organization_by_vanity')]);

    $mention = (new LinkedInMentionResolver('202611'))->organization('tok', 'acme');

    expect($mention->urn)->toBe(Kit::ORG)
        ->and($mention->name)->toBe('Acme Inc');

    Http::assertSent(fn (Request $r) => $r->hasHeader('LinkedIn-Version', '202611')
        && $r->hasHeader('Authorization', 'Bearer tok')
        && $r['vanityName'] === 'acme');
});

it('resolves a member through an organization they administer', function () {
    Http::fake([
        'api.linkedin.com/rest/vanityUrl*' => Kit::fixture('vanity_url_member'),
        'api.linkedin.com/rest/people/*' => Kit::fixture('people_jane'),
    ]);

    $mention = (new LinkedInMentionResolver)->fromUrl('tok', 'https://www.linkedin.com/in/jane-doe', Kit::ORG);

    expect($mention->urn)->toBe('urn:li:person:Jd0eXyZ12')
        ->and($mention->name)->toBe('Jane Doe');
});

it('returns null when LinkedIn knows no such entity', function (array $fakes, Closure $call) {
    Http::fake($fakes);

    expect($call(new LinkedInMentionResolver))->toBeNull();
})->with([
    'no organization' => [fn () => ['api.linkedin.com/v2/organizations*' => Kit::fixture('organization_by_vanity_empty')], fn ($r) => $r->organization('t', 'nobody')],
    'organization 404' => [fn () => ['api.linkedin.com/v2/organizations*' => Http::response(Kit::json('error_404_video'), 404)], fn ($r) => $r->organization('t', 'nobody')],
    'no member' => [fn () => ['api.linkedin.com/rest/vanityUrl*' => Http::response(['elements' => []])], fn ($r) => $r->person('t', Kit::ORG, 'nobody')],
    'profile 404' => [fn () => ['api.linkedin.com/rest/vanityUrl*' => Kit::fixture('vanity_url_member'), 'api.linkedin.com/rest/people/*' => Http::response('', 404)], fn ($r) => $r->person('t', Kit::ORG, 'jane')],
    'profile without names' => [fn () => ['api.linkedin.com/rest/vanityUrl*' => Kit::fixture('vanity_url_member'), 'api.linkedin.com/rest/people/*' => Http::response(['id' => 'x'])], fn ($r) => $r->person('t', Kit::ORG, 'jane')],
    'person without organization' => [fn () => [], fn ($r) => $r->fromUrl('t', 'https://www.linkedin.com/in/jane')],
    'not a linkedin url' => [fn () => [], fn ($r) => $r->fromUrl('t', 'https://example.com')],
]);

it('throws when the lookup itself fails', function (int $status, string $class, FailureReason $reason) {
    Http::fake(['api.linkedin.com/v2/organizations*' => Http::response(['message' => 'nope access_token=secret'], $status)]);

    $e = Kit::catch(fn () => (new LinkedInMentionResolver)->organization('t', 'acme'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['operation'])->toBe('resolve_mention');
})->with([
    [401, PermanentException::class, FailureReason::InvalidToken],
    [403, PermanentException::class, FailureReason::InsufficientPermissions],
    [429, TemporaryException::class, FailureReason::RateLimited],
    [503, TemporaryException::class, FailureReason::ServerError],
    [400, PermanentException::class, FailureReason::Unknown],
]);

it('uses a container-bound resolver for autoMentionUrls', function () {
    app()->instance(LinkedInMentionResolver::class, new LinkedInMentionResolver('209901'));
    Http::fake(['api.linkedin.com/v2/organizations*' => Kit::fixture('organization_by_vanity')]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'Hi linkedin.com/company/acme', options: new LinkedInOptions(autoMentionUrls: true)));

    expect($pending->state['commentary'])->toBe('Hi @[Acme Inc]('.Kit::ORG.')');
    Http::assertSent(fn (Request $r) => $r->hasHeader('LinkedIn-Version', '209901'));
});
