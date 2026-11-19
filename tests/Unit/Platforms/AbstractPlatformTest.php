<?php

namespace SocialPoster\Tests\Unit\Platforms;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\UploadsInChunks;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * The smallest possible driver: everything comes from AbstractPlatform, and
 * call() exposes the protected helpers to the test.
 */
class AbstractPlatformTestDriver extends AbstractPlatform
{
    use UploadsInChunks;

    /** @var array<string, array<int, string|array<int, string>>> */
    public array $scopes = [];

    /** @var list<string> */
    public array $identity = ['account_id'];

    /** @var array<string, string> */
    public array $required = [];

    public ?string $lastOperation = null;

    public function platform(): Platform
    {
        return Platform::Mastodon;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            new CaptionRules(0, 500),
            new MediaRules(0, 4, null, new VideoRules),
        );
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);

        return $this->published(PostResult::success(Platform::Mastodon, '1'));
    }

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }

    protected function requiredCredentials(): array
    {
        return $this->required !== [] ? $this->required : parent::requiredCredentials();
    }

    protected function identityKeys(): array
    {
        return $this->identity;
    }

    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return $this->scopes[$feature->value] ?? [];
    }

    /** A driver may take extra classification arguments; mapError() forwards them. */
    protected function classifyError($response, string $operation = 'post'): TemporaryException|PermanentException
    {
        $this->lastOperation = $operation;

        return parent::classifyError($response);
    }
}

class AbstractPlatformTestResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return $dispatched;
    }
}

class AbstractPlatformTestAuthorizer implements AuthorizesRequests
{
    public function request(Platform $platform, Credentials $credentials): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'DPoP '.$credentials->get('access_token'), 'DPoP' => 'proof']);
    }
}

function apDriver(): AbstractPlatformTestDriver
{
    return new AbstractPlatformTestDriver;
}

/** @param array<string, mixed> $credentials */
function apPost(array $credentials = [], array $media = []): PreparedPost
{
    return new PreparedPost(Platform::Mastodon, new SocialPost($media, 'Hello'), new Credentials($credentials));
}

/** @param array<string, string|array<int, string>> $headers */
function apResponse(int $status, mixed $body = null, array $headers = []): Response
{
    if (is_array($body)) {
        $body = json_encode($body);
        $headers += ['Content-Type' => 'application/json'];
    }

    return new Response(new PsrResponse($status, $headers, (string) $body));
}

function apCatch(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

function apTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'sp-ap-');
    file_put_contents($path, $contents);

    return $path;
}

// --- Default classification ---------------------------------------------------

it('classifies by HTTP status with the platform message when there is one', function (int $status, mixed $body, string $class, FailureReason $reason, string $message) {
    $e = apDriver()->call('classifyError', apResponse($status, $body));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->toBe($message)
        ->and($e->context['http_status'])->toBe($status)
        ->and($e->context['status'])->toBe($status);
})->with([
    '401 x problem' => [401, ['title' => 'Unauthorized', 'type' => 'about:blank', 'status' => 401, 'detail' => 'Unauthorized'], PermanentException::class, FailureReason::InvalidToken, 'Unauthorized'],
    '401 no body' => [401, '', PermanentException::class, FailureReason::InvalidToken, 'Mastodon rejected the access token (HTTP 401); the account must be reconnected.'],
    '403 linkedin' => [403, ['status' => 403, 'serviceErrorCode' => 100, 'code' => 'ACCESS_DENIED', 'message' => 'Not enough permissions to access: partnerApiPostsExternal.CREATE.20240501'], PermanentException::class, FailureReason::InsufficientPermissions, 'Not enough permissions to access: partnerApiPostsExternal.CREATE.20240501'],
    '404 pinterest' => [404, ['code' => 2, 'message' => 'Board not found.'], PermanentException::class, FailureReason::NotFound, 'Board not found.'],
    '418 unknown' => [418, ['message' => 'I am a teapot'], PermanentException::class, FailureReason::Unknown, 'I am a teapot (HTTP 418)'],
    '400 non-json' => [400, 'Bad Request', PermanentException::class, FailureReason::Unknown, 'Mastodon request failed with HTTP 400.'],
    '408' => [408, '', TemporaryException::class, FailureReason::ServerError, 'Mastodon is temporarily unavailable (HTTP 408).'],
    '425' => [425, '', TemporaryException::class, FailureReason::ServerError, 'Mastodon is temporarily unavailable (HTTP 425).'],
    '429 bluesky xrpc' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], TemporaryException::class, FailureReason::RateLimited, 'Rate Limit Exceeded'],
    '500 meta' => [500, ['error' => ['message' => 'An unknown error has occurred.', 'type' => 'OAuthException', 'code' => 1]], TemporaryException::class, FailureReason::ServerError, 'An unknown error has occurred.'],
    '502 html proxy' => [502, '<html><body>Bad gateway</body></html>', TemporaryException::class, FailureReason::ServerError, 'Mastodon is temporarily unavailable (HTTP 502).'],
    'meta user message wins' => [400, ['error' => ['message' => '(#100) Invalid parameter', 'error_user_msg' => 'The video is too long.']], PermanentException::class, FailureReason::Unknown, 'The video is too long. (HTTP 400)'],
    'oauth error string' => [400, ['error' => 'invalid_request'], PermanentException::class, FailureReason::Unknown, 'invalid_request (HTTP 400)'],
]);

it('reads the retry delay from Retry-After or the reset headers', function () {
    $driver = apDriver();

    $seconds = $driver->call('classifyError', apResponse(429, '', ['Retry-After' => '120']));
    $date = $driver->call('classifyError', apResponse(503, '', ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 300).' GMT']));
    $epoch = $driver->call('classifyError', apResponse(429, '', ['x-rate-limit-reset' => (string) (time() + 600)]));
    $none = $driver->call('classifyError', apResponse(500, ''));

    expect($seconds->retryAfter)->toBe(120)
        ->and($date->retryAfter)->toBeGreaterThanOrEqual(298)->toBeLessThanOrEqual(300)
        ->and($epoch->retryAfter)->toBeGreaterThanOrEqual(598)->toBeLessThanOrEqual(600)
        ->and($none->retryAfter)->toBeNull();
});

// --- mapError -------------------------------------------------------------------

it('forwards extra mapError arguments to classifyError and merges identifiers into the context', function () {
    $driver = apDriver();
    $response = apResponse(422, [
        'status' => 422,
        'serviceErrorCode' => 100,
        'code' => 'UNPROCESSABLE_ENTITY',
        'message' => 'Invalid commentary',
        'errorDetails' => ['inputErrors' => [['code' => 'INVALID_VALUE'], ['code' => 'FIELD_TOO_LONG']]],
    ]);

    $e = $driver->call('mapError', $response, 'comment');

    expect($driver->lastOperation)->toBe('comment')
        ->and($e->reason)->toBe(FailureReason::Unknown)
        ->and($e->context['http_status'])->toBe(422)
        ->and($e->context['code'])->toBe('UNPROCESSABLE_ENTITY')
        ->and($e->context['service_error_code'])->toBe(100)
        ->and($e->context['input_errors'])->toBe(['INVALID_VALUE', 'FIELD_TOO_LONG'])
        ->and($e->context)->not->toHaveKey('message');

    $driver->call('mapError', apResponse(500, ''));
    expect($driver->lastOperation)->toBe('post');
});

it('keeps context keys the driver set when merging identifiers', function () {
    $driver = new class extends AbstractPlatformTestDriver
    {
        protected function classifyError($response, string $operation = 'post'): TemporaryException|PermanentException
        {
            return new PermanentException('custom', Platform::Mastodon, FailureReason::MediaRejected, ['code' => 'driver-code']);
        }
    };

    $e = $driver->call('mapError', apResponse(400, ['error' => ['code' => 100, 'error_subcode' => 2207026, 'fbtrace_id' => 'AbC']]));

    expect($e->context['code'])->toBe('driver-code')
        ->and($e->context['subcode'])->toBe(2207026)
        ->and($e->context['trace_id'])->toBe('AbC');
});

it('retries a rejected token once with re-resolved credentials only when a resolver is bound', function () {
    $driver = apDriver();
    $unauthorized = fn () => apResponse(401, ['message' => 'Token expired']);

    $withoutResolver = $driver->call('mapError', $unauthorized());
    expect($withoutResolver)->toBeInstanceOf(PermanentException::class)
        ->and($withoutResolver->reason)->toBe(FailureReason::InvalidToken);

    app()->instance(CredentialsResolver::class, new AbstractPlatformTestResolver);

    $e = $driver->call('mapError', $unauthorized());

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->retryAfter)->toBe(0)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class)
        ->and($e->getPrevious()->reason)->toBe(FailureReason::InvalidToken);
});

it('never re-resolves a terminal rejection or a non-token failure', function () {
    app()->instance(CredentialsResolver::class, new AbstractPlatformTestResolver);

    $terminal = new class extends AbstractPlatformTestDriver
    {
        protected function classifyError($response, string $operation = 'post'): TemporaryException|PermanentException
        {
            return new PermanentException('password changed', Platform::Mastodon, FailureReason::InvalidToken, ['terminal' => true]);
        }
    };

    expect($terminal->call('mapError', apResponse(401, '')))->toBeInstanceOf(PermanentException::class)
        ->and(apDriver()->call('mapError', apResponse(403, '')))->toBeInstanceOf(PermanentException::class);
});

it('does not treat the default identity resolver as a re-resolve source', function () {
    config(['social.credentials_resolver' => IdentityCredentialsResolver::class]);

    expect(apDriver()->call('mapError', apResponse(401, '')))->toBeInstanceOf(PermanentException::class);

    config(['social.credentials_resolver' => AbstractPlatformTestResolver::class]);

    expect(apDriver()->call('mapError', apResponse(401, '')))->toBeInstanceOf(TemporaryException::class);
});

// --- reportError ---------------------------------------------------------------

it('logs temporary failures as warnings and permanent ones as errors', function () {
    config(['social.error_logging' => true]);
    Log::spy();

    apDriver()->call('mapError', apResponse(503, ''));
    apDriver()->call('mapError', apResponse(400, ['message' => 'nope']));

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('error')->once();
});

it('logs identifiers for every platform error shape', function (mixed $body, array $expected) {
    config(['social.error_logging' => true]);
    Log::spy();

    apDriver()->call('mapError', apResponse(400, $body));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($expected) {
        foreach ($expected as $key => $value) {
            if (($context['identifiers'][$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    });
})->with([
    'meta' => [['error' => ['message' => '(#100) bad', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 33, 'fbtrace_id' => 'Tr4ce']], ['code' => 100, 'subcode' => 33, 'type' => 'OAuthException', 'message' => '(#100) bad', 'trace' => 'Tr4ce']],
    'pinterest' => [['code' => 3, 'message' => 'Invalid board'], ['code' => 3, 'message' => 'Invalid board']],
    'bluesky' => [['error' => 'InvalidRequest', 'message' => 'Record/text must not be longer than 300 graphemes'], ['code' => 'InvalidRequest', 'message' => 'Record/text must not be longer than 300 graphemes']],
    'x problem' => [['title' => 'Forbidden', 'detail' => 'You are not permitted to perform this action.', 'type' => 'about:blank', 'status' => 403], ['problem_type' => 'about:blank', 'message' => 'You are not permitted to perform this action.']],
    'linkedin' => [['status' => 400, 'serviceErrorCode' => 100, 'code' => 'INVALID_ARGUMENT', 'message' => 'Bad author', 'errorDetails' => ['inputErrors' => [['code' => 'INVALID_URN_ID']]]], ['code' => 'INVALID_ARGUMENT', 'service_error_code' => 100, 'input_errors' => ['INVALID_URN_ID'], 'message' => 'Bad author']],
    'google' => [['error' => ['code' => 400, 'message' => 'Bad', 'errors' => [['reason' => 'invalidTitle']]]], ['code' => 400, 'reason' => 'invalidTitle']],
]);

it('never logs authorization headers or tokens', function () {
    config(['social.error_logging' => true]);
    Log::spy();

    $driver = new class extends AbstractPlatformTestDriver
    {
        protected function classifyError($response, string $operation = 'post'): TemporaryException|PermanentException
        {
            return new PermanentException('failed calling https://graph.example/me?access_token=SECRET1', Platform::Mastodon, FailureReason::Unknown, [
                'access_token' => 'SECRET2',
                'token_expired' => true,
                'request' => ['headers' => ['Authorization' => 'Bearer SECRET3']],
            ]);
        }
    };

    $driver->call('mapError', apResponse(400, [
        'error' => ['message' => 'bad'],
        'detail' => 'Token Bearer SECRET7.abc_def== was rejected',
        'paging' => ['next' => 'https://graph.facebook.com/v25.0/1/feed?access_token=SECRET4&limit=25'],
    ], [
        'Authorization' => 'Bearer SECRET5',
        'Set-Cookie' => 'session=SECRET6',
        'WWW-Authenticate' => 'Bearer error="invalid_token"',
    ]));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        $dump = $message.json_encode($context);

        return ! str_contains($dump, 'SECRET')
            && ! array_key_exists('Authorization', $context['response_headers'])
            && ! array_key_exists('Set-Cookie', $context['response_headers'])
            && $context['response_headers']['WWW-Authenticate'] === ['Bearer error="invalid_token"']
            && $context['exception_context']['token_expired'] === true
            && $context['classified_as'] === 'permanent';
    });
});

it('redacts a quoted Bearer or DPoP token in a raw body and keeps challenge parameters', function () {
    config(['social.error_logging' => true]);
    Log::spy();

    $driver = new class extends AbstractPlatformTestDriver
    {
        protected function classifyError($response, string $operation = 'post'): TemporaryException|PermanentException
        {
            return new TemporaryException('upstream echoed "Bearer SECRET1" back', Platform::Mastodon);
        }
    };

    // A proxy error page and a truncated JSON echo: neither decodes, so the
    // raw text goes through the string redaction.
    $driver->call('mapError', apResponse(502, '<pre>{"headers":{"authorization":"Bearer SECRET2","dpop":"x"},"auth":"DPoP SECRET3==","alt":\'Bearer SECRET4\',"padded":"Bearer abcSECRET5=","h":"Bearer realm="api", error="invalid_token", error_description="expired"</pre>{"trunc', [
        'WWW-Authenticate' => 'DPoP algs="ES256", error="use_dpop_nonce"',
    ]));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $dump = $message.json_encode($context);

        return ! str_contains($dump, 'SECRET')
            && str_contains($context['response_body'], 'Bearer [redacted]"')
            && str_contains($context['response_body'], 'DPoP [redacted]"')
            && str_contains($context['response_body'], 'Bearer realm="api", error="invalid_token", error_description="expired"')
            && $context['response_headers']['WWW-Authenticate'] === ['DPoP algs="ES256", error="use_dpop_nonce"'];
    });
});

it('logs nothing when error logging is off', function () {
    config(['social.error_logging' => false]);
    Log::spy();

    apDriver()->call('mapError', apResponse(500, ''));

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

// --- Ids and outcomes ---------------------------------------------------------

it('returns a present id and refuses a blank one', function () {
    $driver = apDriver();

    expect($driver->call('requireId', apResponse(200, ['id' => 17841]), 'container'))->toBe('17841')
        ->and($driver->call('requireId', apResponse(200, ['data' => ['id' => ' 190 ']]), 'post', 'data.id'))->toBe('190');

    $temporary = apCatch(fn () => $driver->call('requireId', apResponse(200, ['id' => '']), 'container'));

    expect($temporary)->toBeInstanceOf(TemporaryException::class)
        ->and($temporary->reason)->toBe(FailureReason::ServerError)
        ->and($temporary->getMessage())->toContain('no id for the container')
        ->and($temporary->context['missing_field'])->toBe('id')
        ->and($temporary->possiblyPublished())->toBeFalse();

    $ambiguous = apCatch(fn () => $driver->call('requireId', apResponse(201, ['data' => []]), 'post', 'data.id', true));

    expect($ambiguous)->toBeInstanceOf(PermanentException::class)
        ->and($ambiguous->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($ambiguous->possiblyPublished())->toBeTrue();

    $nonJson = apCatch(fn () => $driver->call('requireId', apResponse(200, 'OK'), 'upload'));
    expect($nonJson)->toBeInstanceOf(TemporaryException::class);
});

it('normalises a blank id and url to null on success', function () {
    $outcome = apDriver()->call('published', new PostResult(Platform::Mastodon, true, '', ' ', ['a' => 1], null, [], PostResult::STATE_PUBLISHED, 'id'));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBeNull()
        ->and($outcome->result->url)->toBeNull()
        ->and($outcome->result->payload)->toBe(['a' => 1])
        ->and($outcome->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($outcome->result->idKind)->toBe('id');

    $kept = apDriver()->call('published', PostResult::success(Platform::Mastodon, '42', 'https://x.test/42'));
    expect($kept->result->platformPostId)->toBe('42');
});

it('builds commit and pending outcomes', function () {
    $commit = apDriver()->call('commit', ['phase' => 'publish'], 0, 1_900_000_000);
    $pending = apDriver()->call('pending', ['phase' => 'poll'], 15);

    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->recheckAfter)->toBe(0)
        ->and($commit->deadline)->toBe(1_900_000_000)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->recheckAfter)->toBe(15)
        ->and($pending->committed)->toBeFalse()
        ->and($pending->deadline)->toBeNull();

    // Committed or deadline Pendings come from PublishOutcome::pending(): the
    // protected pending() hook keeps its v1.0 signature (see the next test).
    $committed = PublishOutcome::pending(['phase' => 'await'], 30, true, 1_900_000_000);

    expect($committed->commits)->toBeFalse()
        ->and($committed->committed)->toBeTrue()
        ->and($committed->deadline)->toBe(1_900_000_000);
});

it('keeps the v1.0 pending() hook signature, so drivers that override it still load', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter) => $parameter->getName(),
        (new ReflectionMethod(AbstractPlatform::class, 'pending'))->getParameters(),
    );

    expect($parameters)->toBe(['state', 'recheckAfter']);

    // A third-party v1.0 driver that clamps its own poll interval. With an
    // incompatible parent signature this declaration is a fatal error.
    $legacy = new class extends AbstractPlatformTestDriver
    {
        protected function pending(array $state, int $recheckAfter = 30): Pending
        {
            return parent::pending($state, max(60, $recheckAfter));
        }
    };

    $outcome = $legacy->call('pending', ['phase' => 'poll'], 5);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->state)->toBe(['phase' => 'poll'])
        ->and($outcome->committed)->toBeFalse();
});

it('runs media through the bound transformer, and leaves it alone without one', function () {
    $media = Media::image('/tmp/photo.png');
    $converted = Media::image('/tmp/sp_converted.jpg');

    expect(apDriver()->call('transformMedia', apPost(), $media))->toBe($media);

    app()->instance(MediaTransformer::class, new class($converted) implements MediaTransformer
    {
        public ?Platform $platform = null;

        public function __construct(private Media $converted) {}

        public function transform(Media $media, Platform $platform, Capabilities $caps): Media
        {
            $this->platform = $platform;

            return $this->converted;
        }
    });

    expect(apDriver()->call('transformMedia', apPost(), $media))->toBe($converted)
        ->and(app(MediaTransformer::class)->platform)->toBe(Platform::Mastodon);
});

it('clamps delays to what the queue can honour', function () {
    $driver = apDriver();

    expect($driver->call('clampDelay', 0))->toBe(5)
        ->and($driver->call('clampDelay', -10))->toBe(5)
        ->and($driver->call('clampDelay', 120))->toBe(120)
        ->and($driver->call('clampDelay', 86400))->toBe(900);

    config(['social.max_recheck_after' => 300]);
    expect($driver->call('clampDelay', 600))->toBe(300);

    config(['social.max_recheck_after' => 5000]);
    expect($driver->call('clampDelay', 3000))->toBe(900);
});

// --- Media streams --------------------------------------------------------------

it('streams a local file and refuses unreadable or remote paths', function () {
    $path = apTempFile('video-bytes');
    $driver = apDriver();

    $stream = $driver->call('stream', Media::video($path));
    expect((string) $stream)->toBe('video-bytes')
        ->and($driver->call('bytes', Media::video($path)))->toBe('video-bytes');

    $missing = apCatch(fn () => $driver->call('stream', Media::video('/nonexistent/sp-missing.mp4')));
    expect($missing)->toBeInstanceOf(PermanentException::class)
        ->and($missing->reason)->toBe(FailureReason::MediaRejected);

    $bytes = apCatch(fn () => $driver->call('bytes', Media::image('/nonexistent/sp-missing.jpg')));
    expect($bytes->reason)->toBe(FailureReason::MediaRejected);

    $remote = apCatch(fn () => $driver->call('stream', Media::video('https://cdn.example.com/v.mp4')));
    expect($remote->reason)->toBe(FailureReason::Misconfigured);

    @unlink($path);
});

it('reads files in bounded chunks and budgets each run', function () {
    $path = apTempFile('0123456789');
    $driver = apDriver();

    expect($driver->call('readChunk', $path, 0, 4))->toBe('0123')
        ->and($driver->call('readChunk', $path, 8, 4))->toBe('89')
        ->and($driver->call('readChunk', $path, 10, 4))->toBe('')
        ->and($driver->call('readChunk', $path, 3, 0))->toBe('')
        ->and($driver->call('uploadSize', $path))->toBe(10)
        ->and($driver->call('runBudgetSeconds'))->toBe(240)
        ->and($driver->call('budgetLeft', microtime(true)))->toBeTrue()
        ->and($driver->call('budgetLeft', microtime(true) - 241))->toBeFalse();

    config(['social.api.mastodon.upload_run_seconds' => 30]);
    expect($driver->call('budgetLeft', microtime(true) - 31))->toBeFalse();

    expect(apCatch(fn () => $driver->call('readChunk', '/nonexistent/sp.bin', 0, 4))->reason)->toBe(FailureReason::MediaRejected)
        ->and(apCatch(fn () => $driver->call('uploadSize', apTempFile('')))->reason)->toBe(FailureReason::MediaRejected);

    @unlink($path);
});

// --- Credentials ----------------------------------------------------------------

it('names every missing credential as Misconfigured before any request', function () {
    $driver = apDriver();
    $driver->required = ['account_id' => 'Page id', 'page_access_token|access_token' => 'Page access token'];

    $e = apCatch(fn () => $driver->publish(apPost(['account_id' => ' '])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain('account_id (Page id)')
        ->and($e->getMessage())->toContain('page_access_token|access_token')
        ->and($e->context['missing_credentials'])->toBe(['account_id', 'page_access_token|access_token']);

    // Any alternative satisfies a key.
    expect($driver->publish(apPost(['account_id' => '1', 'access_token' => 't'])))->toBeInstanceOf(Published::class);

    Http::assertNothingSent();
});

it('points an unresolved reference at the credentials resolver', function () {
    $e = apCatch(fn () => apDriver()->publish(new PreparedPost(Platform::Mastodon, new SocialPost, Credentials::ref('social_account:42'))));

    expect($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain('social_account:42')
        ->and($e->getMessage())->toContain('CredentialsResolver');
});

it('refuses a token about to expire, permanently without a resolver', function () {
    $driver = apDriver();

    $driver->call('assertTokenFresh', apPost(['access_token' => 't']));
    $driver->call('assertTokenFresh', apPost(['access_token' => 't', 'expires_at' => time() + 3600]));

    $e = apCatch(fn () => $driver->call('assertTokenFresh', apPost(['access_token' => 't', 'expires_at' => time() + 30])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['token_expired'])->toBeTrue();

    $margin = apCatch(fn () => $driver->call('assertTokenFresh', apPost(['access_token' => 't', 'expires_at' => time() + 500]), 600));
    expect($margin)->toBeInstanceOf(PermanentException::class);
});

it('asks for a re-resolve instead when a resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new AbstractPlatformTestResolver);

    $e = apCatch(fn () => apDriver()->call('assertTokenFresh', apPost(['access_token' => 't', 'expires_at' => date(DATE_ATOM, time() - 10)])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->retryAfter)->toBe(0)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->context['token_expired'])->toBeTrue();
});

it('derives a stable account identity from whitelisted keys only', function () {
    $driver = apDriver();

    $a = $driver->accountKey(new Credentials(['account_id' => '1784', 'access_token' => 'one', 'expires_at' => 1, 'scopes' => 'a']));
    $b = $driver->accountKey(new Credentials(['account_id' => '1784', 'access_token' => 'two', 'expires_at' => 2, 'scopes' => 'a,b']));

    expect($a)->toBe('1784')->and($b)->toBe($a);

    $driver->identity = ['board_id', 'board_section_id'];
    expect($driver->accountKey(new Credentials(['board_id' => '55', 'access_token' => 't'])))->toBe('55')
        ->and($driver->accountKey(new Credentials(['board_id' => '55', 'board_section_id' => '9'])))->toBe('55:9')
        ->and($driver->accountKey(new Credentials(['account_id' => '55', 'board_section_id' => '9', 'account' => 'acme'])))->toBe('acme');

    expect($driver->accountKey(Credentials::ref('social_account:7')))->toBe('ref:social_account:7');

    // A token is never an identity: it changes on refresh (v1.0 scoping, '').
    expect($driver->accountKey(new Credentials(['access_token' => 'tok-123'])))->toBe('')
        ->and($driver->accountKey(new Credentials(['access_token' => 'tok-456'])))->toBe('')
        ->and($driver->accountKey(new Credentials(['expires_at' => 5])))->toBe('');
});

it('exposes the identity credential keys read-only', function () {
    $driver = apDriver();

    expect($driver->identityCredentialKeys())->toBe(['account_id']);

    $driver->identity = ['board_id', 'board_section_id'];

    expect($driver->identityCredentialKeys())->toBe(['board_id', 'board_section_id']);
});

// --- Scopes -------------------------------------------------------------------

it('reports missing scopes only when the credentials list them', function () {
    $driver = apDriver();
    $driver->scopes = [
        'publish' => [['write:statuses', 'write'], 'read:accounts'],
        'video' => [['write:media']],
    ];

    $video = Media::video(apTempFile('v'), meta: ['size_bytes' => 1]);

    $unknown = $driver->validate(apPost(['access_token' => 't'], [$video]));
    expect($unknown->has('credentials.scopes'))->toBeFalse();

    $missing = $driver->validate(apPost(['access_token' => 't', 'scopes' => 'write read:other'], [$video]));
    expect($missing->get('credentials.scopes'))->toHaveCount(2)
        ->and(implode(' ', $missing->get('credentials.scopes')))->toContain('read:accounts')->toContain('write:media');

    $granted = $driver->validate(apPost(['access_token' => 't', 'scopes' => ['write:statuses', 'read:accounts', 'write:media']], [$video]));
    expect($granted->has('credentials.scopes'))->toBeFalse();

    expect($driver->scopesFor(Feature::Publish))->toBe([['write:statuses', 'write'], ['read:accounts']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([])
        ->and($driver->missingScopes(Feature::Publish, new Credentials))->toBeNull()
        ->and($driver->missingScopes(Feature::Publish, new Credentials(['scopes' => 'write'])))->toBe([['read:accounts']]);
});

// --- HTTP client --------------------------------------------------------------

it('sends a bearer token with the package timeouts by default', function () {
    Http::fake(['api.test/*' => Http::response(['ok' => true])]);

    $request = apDriver()->call('http', apPost(['access_token' => 'tok']));
    $options = $request->getOptions();

    expect($options['connect_timeout'])->toBe(10)
        ->and($options['timeout'])->toBe(60);

    $request->get('https://api.test/me');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok'));

    config(['social.http_timeout' => 45]);
    expect(apDriver()->call('http', apPost(['access_token' => 'tok']))->getOptions()['timeout'])->toBe(45);

    config(['social.api.mastodon.timeout' => 90]);
    expect(apDriver()->call('http', apPost(['access_token' => 'tok']))->getOptions()['timeout'])->toBe(90);
});

it('starts from the configured authorizer instead of a bearer token', function () {
    Http::fake(['api.test/*' => Http::response(['ok' => true])]);
    config(['social.authorizers.mastodon' => AbstractPlatformTestAuthorizer::class]);

    $request = apDriver()->call('http', apPost(['access_token' => 'tok']));
    $request->post('https://api.test/statuses', ['status' => 'hi']);

    expect($request->getOptions()['timeout'])->toBe(60);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'DPoP tok') && $r->hasHeader('DPoP', 'proof'));
});

it('fails as Misconfigured when the authorizer is not an AuthorizesRequests', function () {
    config(['social.authorizers.mastodon' => \stdClass::class]);

    $e = apCatch(fn () => apDriver()->call('http', apPost(['access_token' => 'tok'])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);

    config(['social.authorizers.mastodon' => 'Missing\\Authorizer\\Class']);
    expect(apCatch(fn () => apDriver()->call('http', apPost()))->reason)->toBe(FailureReason::Misconfigured);
});

it('keeps third-party drivers that only implement the v1.0 hooks working', function () {
    $legacy = new class extends AbstractPlatform
    {
        public function platform(): Platform
        {
            return Platform::Mastodon;
        }

        public function capabilities(): Capabilities
        {
            return new Capabilities(new CaptionRules(0, 500), new MediaRules(0, 4));
        }

        public function publish(PreparedPost $post): PublishOutcome
        {
            $response = $this->http($post)->post('https://api.test/statuses', ['status' => $post->caption()]);

            if (! $this->ok($response)) {
                throw $this->mapError($response);
            }

            return $this->published(PostResult::success(Platform::Mastodon, (string) $response->json('id')));
        }

        protected function classifyError($response): TemporaryException|PermanentException
        {
            return $response->status() === 422
                ? new PermanentException('bad', Platform::Mastodon, FailureReason::InvalidContent)
                : parent::classifyError($response);
        }
    };

    Http::fake(['api.test/*' => Http::sequence()->push(['id' => ''], 200)->push(['error' => 'x'], 422)->push('', 503)]);

    $post = apPost(['access_token' => 't']);

    expect($legacy->publish($post)->result->platformPostId)->toBeNull()
        ->and(apCatch(fn () => $legacy->publish($post))->reason)->toBe(FailureReason::InvalidContent)
        ->and(apCatch(fn () => $legacy->publish($post)))->toBeInstanceOf(TemporaryException::class)
        ->and($legacy->accountKey(new Credentials(['account_id' => '9'])))->toBe('9')
        ->and($legacy->validate(apPost())->isEmpty())->toBeTrue();
});
