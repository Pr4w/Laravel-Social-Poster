<?php

use Illuminate\Support\Carbon;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

describe('Credentials', function () {
    it('keeps the v1.0 accessors', function () {
        $credentials = Credentials::make(['account_id' => '1784', 'access_token' => 'tok', 'blank' => '']);

        expect($credentials->values)->toBe(['account_id' => '1784', 'access_token' => 'tok', 'blank' => ''])
            ->and($credentials->get('account_id'))->toBe('1784')
            ->and($credentials->get('missing', 'default'))->toBe('default')
            ->and($credentials->has('blank'))->toBeTrue()
            ->and($credentials->has('missing'))->toBeFalse()
            ->and((new Credentials)->values)->toBe([]);
    });

    it('tells filled values from blank ones', function () {
        $credentials = new Credentials(['a' => 'x', 'b' => '  ', 'c' => null, 'd' => [], 'e' => 0, 'f' => false]);

        expect($credentials->filled('a'))->toBeTrue()
            ->and($credentials->filled('b'))->toBeFalse()
            ->and($credentials->filled('c'))->toBeFalse()
            ->and($credentials->filled('d'))->toBeFalse()
            ->and($credentials->filled('e'))->toBeTrue()
            ->and($credentials->filled('f'))->toBeTrue()
            ->and($credentials->filled('missing'))->toBeFalse();
    });

    it('merges values into a copy', function () {
        $original = Credentials::ref('social_account:42', ['account_id' => '1784']);
        $resolved = $original->with(['access_token' => 'fresh', 'account_id' => '1785']);

        expect($resolved)->not->toBe($original)
            ->and($resolved->get('access_token'))->toBe('fresh')
            ->and($resolved->get('account_id'))->toBe('1785')
            ->and($original->has('access_token'))->toBeFalse();
    });

    it('builds a token-free reference', function () {
        $ref = Credentials::ref('social_account:42', ['account_id' => '1784', 'author' => 'urn:li:person:1']);

        expect($ref->isReference())->toBeTrue()
            ->and($ref->reference())->toBe('social_account:42')
            ->and($ref->get('account_id'))->toBe('1784')
            ->and((new Credentials(['access_token' => 'x']))->isReference())->toBeFalse()
            ->and((new Credentials(['access_token' => 'x']))->reference())->toBeNull();
    });

    it('refuses secrets or a blank key in a reference', function (string $key, array $nonSecret) {
        expect(fn () => Credentials::ref($key, $nonSecret))->toThrow(InvalidArgumentException::class);
    })->with([
        'token' => ['acc:1', ['access_token' => 'x']],
        'refresh' => ['acc:1', ['refresh_token' => 'x']],
        'dpop key' => ['acc:1', ['dpop_private_key' => 'pem']],
        'app password' => ['acc:1', ['app_password' => 'x']],
        'blank key' => [' ', []],
    ]);

    it('reads expires_at in every common shape', function (mixed $value, ?int $expected) {
        $expiresAt = (new Credentials(['expires_at' => $value]))->expiresAt();

        expect($expiresAt?->getTimestamp())->toBe($expected);
    })->with([
        'unix seconds' => [1_800_000_000, 1_800_000_000],
        'unix milliseconds' => [1_800_000_000_000, 1_800_000_000],
        'numeric string' => ['1800000000', 1_800_000_000],
        'float' => [1_800_000_000.5, 1_800_000_000],
        'iso string' => ['2027-01-15T08:00:00+00:00', 1_800_000_000],
        'iso string with offset' => ['2027-01-15T10:00:00+02:00', 1_800_000_000],
        'datetime' => [new DateTimeImmutable('@1800000000'), 1_800_000_000],
        'carbon' => [Carbon::createFromTimestamp(1_800_000_000), 1_800_000_000],
        'missing' => [null, null],
        'zero' => [0, null],
        'garbage' => ['not a date', null],
        'array' => [['x'], null],
    ]);

    it('knows when the token expires within a margin', function () {
        $now = 1_800_000_000;

        expect((new Credentials(['expires_at' => $now + 30]))->expiresWithin(60, $now))->toBeTrue()
            ->and((new Credentials(['expires_at' => $now - 30]))->expiresWithin(60, $now))->toBeTrue()
            ->and((new Credentials(['expires_at' => $now + 3600]))->expiresWithin(60, $now))->toBeFalse()
            ->and((new Credentials)->expiresWithin(60, $now))->toBeFalse();
    });

    it('parses the granted scopes and leaves unknown as null', function (mixed $scopes, ?array $expected) {
        expect((new Credentials(['scopes' => $scopes]))->scopes())->toBe($expected);
    })->with([
        'array' => [['tweet.read', 'tweet.write', 'tweet.read'], ['tweet.read', 'tweet.write']],
        'space separated' => ['openid profile w_member_social', ['openid', 'profile', 'w_member_social']],
        'comma separated' => ['user.info.basic,video.publish', ['user.info.basic', 'video.publish']],
        'empty list' => [[], []],
        'null' => [null, null],
    ]);

    it('checks CNF scope groups against the granted scopes', function () {
        $required = [['pages_manage_engagement'], ['pages_read_user_content', 'pages_read_engagement'], 'pages_show_list'];
        $granted = new Credentials(['scopes' => ['pages_manage_engagement', 'pages_read_engagement']]);

        expect($granted->missingScopes($required))->toBe([['pages_show_list']])
            ->and((new Credentials)->missingScopes($required))->toBeNull()
            ->and((new Credentials(['scopes' => 'pages_manage_engagement pages_read_user_content pages_show_list']))->missingScopes($required))->toBe([])
            ->and(Credentials::normaliseScopeGroups(['a', ['b', ' c ', ''], [], ['']]))->toBe([['a'], ['b', 'c']]);
    });

    it('redacts token-like values from debug output', function () {
        $credentials = new Credentials([
            'account_id' => '1784',
            'access_token' => 'SECRET-A',
            'refresh_token' => 'SECRET-B',
            'dpop_private_key' => ['kty' => 'EC', 'd' => 'SECRET-C'],
            'app_password' => 'SECRET-D',
            'access_jwt' => 'SECRET-E',
            'expires_at' => 1_800_000_000,
        ]);

        $printed = print_r($credentials, true);
        $dumped = (function () use ($credentials) {
            ob_start();
            var_dump($credentials);

            return (string) ob_get_clean();
        })();

        expect($printed)->not->toContain('SECRET')->toContain('1784')->toContain('[redacted]')
            ->and($dumped)->not->toContain('SECRET')
            ->and($credentials->redacted()['account_id'])->toBe('1784')
            ->and($credentials->redacted()['access_token'])->toBe('[redacted]')
            ->and($credentials->redacted()['expires_at'])->toBe(1_800_000_000)
            ->and($credentials->get('access_token'))->toBe('SECRET-A');
    });

    it('redacts token-like values from JSON and log context output', function () {
        $credentials = new Credentials(['account_id' => '1784', 'access_token' => 'SECRET-A', 'refresh_token' => 'SECRET-B']);
        $post = new PreparedPost(Platform::Facebook, new SocialPost([], 'Hello'), $credentials);

        // Monolog normalises a context object through json_encode(); so do
        // Telescope's job watcher and most error trackers.
        $normalised = (new NormalizerFormatter)->format(new LogRecord(new DateTimeImmutable, 'app', Level::Error, 'failed', ['post' => $post]));

        expect(json_encode($credentials))->toBe('{"values":{"account_id":"1784","access_token":"[redacted]","refresh_token":"[redacted]"}}')
            ->and(json_encode(['post' => $post]))->not->toContain('SECRET')->toContain('1784')
            ->and(json_encode($normalised))->not->toContain('SECRET')->toContain('1784')
            ->and($credentials->values['access_token'])->toBe('SECRET-A')
            ->and(unserialize(serialize($credentials))->get('access_token'))->toBe('SECRET-A');
    });

    it('flags secret key names', function (string $key, bool $secret) {
        expect(Credentials::isSecretKey($key))->toBe($secret);
    })->with([
        ['access_token', true], ['page_access_token', true], ['refresh_token', true], ['access_jwt', true],
        ['client_secret', true], ['app_password', true], ['dpop_private_key', true], ['api_key', true],
        ['account_id', false], ['author', false], ['did', false], ['board_id', false], ['open_id', false],
        ['expires_at', false], ['scopes', false], ['login', false], ['_ref', false],
    ]);

    it('hides the constructor values from stack traces', function () {
        $parameter = (new ReflectionMethod(Credentials::class, '__construct'))->getParameters()[0];

        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });

    it('still unserializes a credentials bag queued by v1.0', function () {
        $legacy = 'O:'.strlen(Credentials::class).':"'.Credentials::class.'":1:{s:6:"values";a:2:{s:10:"account_id";s:4:"1784";s:12:"access_token";s:3:"tok";}}';

        $credentials = unserialize($legacy);

        expect($credentials)->toBeInstanceOf(Credentials::class)
            ->and($credentials->get('access_token'))->toBe('tok')
            ->and(unserialize(serialize($credentials))->values)->toBe($credentials->values);
    });
});

describe('IdentityCredentialsResolver', function () {
    it('returns the dispatched credentials unchanged', function () {
        $credentials = new Credentials(['access_token' => 'tok']);

        expect((new IdentityCredentialsResolver)->resolve(Platform::X, $credentials, []))->toBe($credentials);
    });

    it('fails a reference as Misconfigured', function () {
        try {
            (new IdentityCredentialsResolver)->resolve(Platform::X, Credentials::ref('social_account:42'), []);
            $this->fail('Expected a PermanentException.');
        } catch (PermanentException $e) {
            expect($e->reason)->toBe(FailureReason::Misconfigured)
                ->and($e->platform)->toBe(Platform::X)
                ->and($e->getMessage())->toContain('social.credentials_resolver')
                ->and($e->context['credentials_ref'])->toBe('social_account:42');
        }
    });

    it('detects whether the app bound its own resolver', function () {
        expect(IdentityCredentialsResolver::customResolverBound())->toBeFalse();

        config(['social.credentials_resolver' => IdentityCredentialsResolver::class]);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeFalse();

        $custom = new class implements CredentialsResolver
        {
            public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
            {
                return $dispatched->with(['access_token' => 'fresh']);
            }
        };

        config(['social.credentials_resolver' => $custom::class]);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeTrue();

        app()->instance(CredentialsResolver::class, new IdentityCredentialsResolver);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeFalse();

        app()->instance(CredentialsResolver::class, $custom);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeTrue();
    });

    it('counts a subclass of the identity resolver as a resolver of the app', function () {
        // Reuses the pass-through and adds reference lookups and refreshes.
        $subclass = new class extends IdentityCredentialsResolver
        {
            public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
            {
                return $dispatched->isReference()
                    ? $dispatched->with(['access_token' => 'fresh'])
                    : parent::resolve($platform, $dispatched, $metadata);
            }
        };

        app()->instance(CredentialsResolver::class, $subclass);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeTrue();

        app()->forgetInstance(CredentialsResolver::class);
        app()->offsetUnset(CredentialsResolver::class);

        config(['social.credentials_resolver' => $subclass::class]);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeTrue();

        config(['social.credentials_resolver' => $subclass]);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeTrue();

        config(['social.credentials_resolver' => '\\'.IdentityCredentialsResolver::class]);
        expect(IdentityCredentialsResolver::customResolverBound())->toBeFalse();
    });
});

describe('CommentRules', function () {
    it('keeps the contract order and defaults', function () {
        $rules = new CommentRules(2200);

        expect($rules->max)->toBe(2200)
            ->and($rules->minDelaySeconds)->toBe(0)
            ->and($rules->requiredScopes)->toBe([])
            ->and($rules->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
            ->and($rules->maxBytes)->toBeNull()
            ->and($rules->counter->count('héllo'))->toBe(5);

        $bluesky = new CommentRules(300, new GraphemeCounter, 0, [], 'attempt', 3000);
        expect($bluesky->maxBytes)->toBe(3000)
            ->and($bluesky->counter)->toBeInstanceOf(GraphemeCounter::class);
    });

    it('validates length in characters and bytes, after trimming', function () {
        $rules = new CommentRules(5, maxBytes: 8);

        expect($rules->fits('  hello  '))->toBeTrue()
            ->and($rules->length('  hello  '))->toBe(5)
            ->and($rules->fits('hello!'))->toBeFalse()
            ->and($rules->errors('hello!')[0])->toContain('limit is 5')
            ->and($rules->fits('ééééé'))->toBeFalse()
            ->and($rules->errors('ééééé'))->toHaveCount(1)
            ->and($rules->errors('ééééé')[0])->toContain('bytes')
            ->and($rules->errors('   '))->toBe(['The comment is empty.']);
    });

    it('waits at least the platform minimum delay', function () {
        $linkedin = new CommentRules(1250, minDelaySeconds: 60);

        expect($linkedin->delay(10))->toBe(60)
            ->and($linkedin->delay(120))->toBe(120)
            ->and((new CommentRules(100))->delay(10))->toBe(10);
    });

    it('decides from the granted scopes and the unknown-scope policy', function () {
        $attempt = new CommentRules(8000, null, 0, [['pages_manage_engagement'], ['pages_read_user_content']]);
        $skip = new CommentRules(500, null, 0, ['threads_manage_replies'], CommentRules::SKIP);

        $unknown = new Credentials(['access_token' => 't']);
        $missing = new Credentials(['scopes' => 'pages_manage_engagement threads_basic']);
        $granted = new Credentials(['scopes' => ['pages_manage_engagement', 'pages_read_user_content', 'threads_manage_replies']]);

        expect($attempt->requiredScopes)->toBe([['pages_manage_engagement'], ['pages_read_user_content']])
            ->and($attempt->skipReason($unknown))->toBeNull()
            ->and($attempt->skipReason($missing))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
            ->and($attempt->skipReason($granted))->toBeNull()
            ->and($attempt->missingScopes($unknown))->toBeNull()
            ->and($attempt->missingScopes($missing))->toBe([['pages_read_user_content']])
            ->and($skip->skipReason($unknown))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
            ->and($skip->skipReason($granted))->toBeNull()
            ->and((new CommentRules(100))->skipReason($missing))->toBeNull()
            ->and((new CommentRules(100))->missingScopes($missing))->toBe([]);
    });

    it('refuses an unknown policy or negative limits', function () {
        expect(fn () => new CommentRules(100, unknownScopePolicy: 'maybe'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new CommentRules(-1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new CommentRules(100, minDelaySeconds: -5))->toThrow(InvalidArgumentException::class);
    });
});

describe('CommentResult', function () {
    it('carries the id, url and payload and nulls blanks', function () {
        $result = new CommentResult('17900', 'https://www.instagram.com/p/abc/c/17900/', ['raw' => ['id' => '17900']]);
        $blank = new CommentResult('', '  ');

        expect($result->id)->toBe('17900')
            ->and($result->url)->toBe('https://www.instagram.com/p/abc/c/17900/')
            ->and($result->toArray())->toBe(['id' => '17900', 'url' => 'https://www.instagram.com/p/abc/c/17900/', 'payload' => ['raw' => ['id' => '17900']]])
            ->and(json_encode($result))->toBe(json_encode($result->toArray()))
            ->and($blank->id)->toBeNull()
            ->and($blank->url)->toBeNull()
            ->and((new CommentResult)->payload)->toBe([]);
    });
});

describe('Feature', function () {
    it('names the extra feature a media type needs', function () {
        expect(Feature::forMedia(MediaType::Video))->toBe(Feature::Video)
            ->and(Feature::forMedia(MediaType::Document))->toBe(Feature::Document)
            ->and(Feature::forMedia(MediaType::Image))->toBeNull()
            ->and(Feature::from('comment'))->toBe(Feature::Comment);
    });
});
