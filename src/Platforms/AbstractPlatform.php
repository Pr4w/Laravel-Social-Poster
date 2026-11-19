<?php

namespace SocialPoster\Platforms;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Psr\Http\Message\StreamInterface;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Concerns\ValidatesAgainstCapabilities;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\Support\RetryAfter;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * Base class for every concrete driver. Slots a driver can fill:
 *
 *   - rulesFor()              the Capabilities that apply to THIS post.
 *   - validatePlatformRules() conditional / cross-field validation.
 *   - prepare()               content transformation into the payload.
 *   - ingestion()             how the platform takes media (pull URL vs upload).
 *   - publish() / resume()    the publish flow; resume() only for async platforms.
 *   - classifyError()         the platform's error table; end with parent::classifyError().
 *   - requiredCredentials()   the credential keys publish/resume/comment need.
 *   - identityKeys()          the credential keys that identify the account.
 *   - requiredScopes()        the OAuth scopes each Feature needs.
 *
 * Helpers drivers use (and never redefine): http(), requireId(), stream(),
 * commit(), clampDelay(), assertCredentials(), assertTokenFresh(), accountKey().
 */
abstract class AbstractPlatform implements SocialPlatform
{
    use ValidatesAgainstCapabilities;

    /** Response header names never written to the error log. */
    private const SECRET_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'dpop'];

    /** Raw (non-JSON) response bodies are cut to this many characters in logs and contexts. */
    private const MAX_LOGGED_BODY = 2000;

    public function __construct(
        protected ?MediaInspector $inspector = null,
        protected ?MediaGateway $gateway = null,
    ) {}

    abstract public function platform(): Platform;

    abstract public function capabilities(): Capabilities;

    public function validate(PreparedPost $post): MessageBag
    {
        $errors = new MessageBag;
        $caps = $this->rulesFor($post);

        $this->validateAgainstCapabilities($post, $caps, $errors);
        $this->validatePlatformRules($post, $errors);
        $this->validateMediaTransfer($post, $errors);
        $this->validateScopes($post, $errors);

        $options = $post->options();

        if ($options !== null && $options->platform() === $this->platform()) {
            $errors->merge($options->validate());
        }

        return $errors;
    }

    abstract public function publish(PreparedPost $post): PublishOutcome;

    /** Synchronous platforms never pend, so this is never called for them. */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        throw new PermanentException(
            "{$this->platform()->value} does not support resumable publishing.",
            $this->platform(),
        );
    }

    /**
     * Whether the queue should run a step with this state again when its
     * worker is killed on timeout, instead of failing it. Only for a step
     * whose repeated run (Pending::ATTEMPT above 1) first asks the platform
     * what the killed run left behind, so running it again cannot create the
     * post twice. Default false: a killed run fails, as possibly published in
     * a commit phase.
     *
     * @param  array<string, mixed>  $state  The driver's state, without the job's reserved keys.
     */
    public function retriesKilledRun(array $state): bool
    {
        return false;
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        return $this->capabilities();
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        //
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    protected function validateMediaTransfer(PreparedPost $post, MessageBag $errors): void
    {
        if ($this->ingestion() !== Ingestion::PullUrl || $this->gateway === null) {
            return;
        }

        foreach ($post->media() as $index => $media) {
            if (! $this->gateway->canProvideUrl($media)) {
                $errors->add(
                    "media.{$index}",
                    "{$this->platform()->value} fetches media from a public URL, but this is a local file. Host it publicly or bind a MediaGateway that can publish local files.",
                );
            }
        }
    }

    /**
     * When the credentials list their granted scopes, report every required
     * scope group they miss under 'credentials.scopes'. Unknown scopes (no
     * 'scopes' key, e.g. during content-only validation) are never an error.
     */
    protected function validateScopes(PreparedPost $post, MessageBag $errors): void
    {
        if ($post->credentials->scopes() === null) {
            return;
        }

        $features = [Feature::Publish];

        foreach ($post->media() as $media) {
            $extra = Feature::forMedia($media->type);

            if ($extra !== null && ! in_array($extra, $features, true)) {
                $features[] = $extra;
            }
        }

        foreach ($features as $feature) {
            foreach ($this->missingScopes($feature, $post->credentials) ?? [] as $group) {
                $errors->add(
                    'credentials.scopes',
                    "The {$this->platformLabel()} token is missing a permission needed to {$feature->value}: grant ".implode(' or ', $group).'.',
                );
            }
        }
    }

    /** Optional content transformation into the platform payload. */
    protected function prepare(PreparedPost $post): array
    {
        return [];
    }

    // --- Outcomes ---------------------------------------------------------------

    /**
     * A terminal success. A blank id or url is stored as null, so an empty
     * string can never reach a comment, a permalink or the consumer's database.
     */
    protected function published(PostResult $result): Published
    {
        $id = $result->platformPostId;
        $url = $result->url;

        if (($id !== null && trim($id) === '') || ($url !== null && trim($url) === '')) {
            $result = new PostResult(
                $result->platform,
                $result->success,
                $id !== null && trim($id) !== '' ? $id : null,
                $url !== null && trim($url) !== '' ? $url : null,
                $result->payload,
                $result->error,
                $result->metadata,
                $result->state,
                $result->idKind,
            );
        }

        return PublishOutcome::published($result);
    }

    /**
     * Continue later. The signature is the v1.0 one on purpose: drivers written
     * for 1.0 override it, and extra parameters here would make them fatal.
     * When the post (or an equivalent remote publish job) already exists, or
     * the driver knows a deadline (unix time), return
     * PublishOutcome::pending($state, $recheckAfter, committed: true,
     * deadline: $deadline) instead: a later failure is then reported as
     * possibly published, and the job honours the deadline before its defaults.
     *
     * @param  array<string, mixed>  $state
     */
    protected function pending(array $state, int $recheckAfter = 30): Pending
    {
        return PublishOutcome::pending($state, $recheckAfter);
    }

    /**
     * Continue with a non-idempotent create on the next resume() (see
     * PublishOutcome::commit()). In that phase, check Pending::isRecovering($state)
     * and verify on the platform before creating again.
     *
     * @param  array<string, mixed>  $state
     */
    protected function commit(array $state, int $recheckAfter = 0, ?int $deadline = null, bool $possiblySent = false): Pending
    {
        return PublishOutcome::commit($state, $recheckAfter, $deadline, $possiblySent);
    }

    /**
     * Clamp a wait to what the queue can honour: at least 5 seconds (no hot
     * loops), at most config('social.max_recheck_after', 900) and never above
     * 900 (SQS's delay limit).
     */
    protected function clampDelay(int $seconds): int
    {
        $max = min(900, max(5, (int) config('social.max_recheck_after', 900)));

        return max(5, min($max, $seconds));
    }

    /**
     * Read a required id off a successful response. A blank id never becomes ''
     * (which would build URLs like /status/ and comment on nothing):
     *
     * - normally it is a TemporaryException(ServerError): retrying is safe;
     * - with $ambiguous (the response answered a non-idempotent create, so the
     *   post may exist) it is a PermanentException(OutcomeUnknown) with
     *   possibly_published = true: never retried blindly.
     *
     * @param  mixed  $response
     * @param  string  $what  What the id names, for the message ('container', 'post', 'upload').
     * @param  string  $path  Dot path of the id in the JSON body ('id', 'data.id').
     */
    protected function requireId($response, string $what, string $path = 'id', bool $ambiguous = false): string
    {
        $value = null;

        try {
            $value = $response->json($path);
        } catch (\Throwable) {
            // Non-JSON body: treated as a missing id below.
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        $context = [
            'status' => $response->status(),
            'http_status' => $response->status(),
            'missing_field' => $path,
            'body' => $this->redactForLog($this->decodedBody($response) ?? $this->truncate((string) $response->body())),
        ];

        $exception = $ambiguous
            ? new PermanentException(
                "{$this->platformLabel()} accepted the request but returned no id for the {$what}; the post may be live, check the account before retrying.",
                $this->platform(),
                FailureReason::OutcomeUnknown,
                $context + ['possibly_published' => true],
            )
            : new TemporaryException(
                "{$this->platformLabel()} returned no id for the {$what}.",
                $this->platform(),
                FailureReason::ServerError,
                null,
                $context,
            );

        $this->reportError($response, $exception);

        throw $exception;
    }

    // --- Media ----------------------------------------------------------------

    protected function inspector(): ?MediaInspector
    {
        return $this->inspector;
    }

    protected function gateway(): ?MediaGateway
    {
        return $this->gateway;
    }

    protected function mediaUrl(Media $media): string
    {
        return $this->gateway?->url($media) ?? $media->source;
    }

    protected function mediaPath(Media $media): string
    {
        return $this->gateway?->path($media) ?? $media->source;
    }

    /**
     * Apply the bound MediaTransformer (ImageRules::$convert: TikTok png → jpg,
     * Instagram JPEG-only...) before mediaUrl()/mediaPath(). Returns $media
     * unchanged when no transformer is bound or it has nothing to do. The job
     * calls the transformer's cleanup() when the publish ends.
     */
    protected function transformMedia(PreparedPost $post, Media $media): Media
    {
        $transformer = $this->mediaTransformer();

        return $transformer === null ? $media : $transformer->transform($media, $this->platform(), $this->rulesFor($post));
    }

    /**
     * The whole file in memory.
     *
     * @deprecated since 1.1 for anything that can be large (videos, documents):
     *             use stream() so the file is read as it is sent. Fine for images
     *             and thumbnails.
     *
     * @throws PermanentException When the file cannot be read (MediaRejected).
     */
    protected function bytes(Media $media): string
    {
        $path = $this->mediaPath($media);
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new PermanentException(
                'The media file could not be read.',
                $this->platform(),
                FailureReason::MediaRejected,
                ['path' => basename($path)],
            );
        }

        return $bytes;
    }

    /**
     * A read-only PSR-7 stream over the media's local file, for request bodies
     * and multipart fields: the file is sent as it is read, never loaded whole.
     * Guzzle closes the handle when the stream is released.
     *
     * @throws PermanentException When the path is not a local file (Misconfigured)
     *                            or cannot be opened (MediaRejected).
     */
    protected function stream(Media $media): StreamInterface
    {
        $path = $this->mediaPath($media);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 && ! str_starts_with(strtolower($path), 'file://')) {
            throw new PermanentException(
                "{$this->platformLabel()} uploads the file itself, but the media is a remote URL and no MediaGateway downloaded it. Bind a MediaGateway (the default LocalMediaGateway downloads remote media).",
                $this->platform(),
                FailureReason::Misconfigured,
            );
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new PermanentException(
                'The media file could not be opened.',
                $this->platform(),
                FailureReason::MediaRejected,
                ['path' => basename($path)],
            );
        }

        return Utils::streamFor($handle);
    }

    // --- Credentials ------------------------------------------------------------

    /**
     * Credential key holding the bearer token for this platform. Overridden by
     * drivers that name it differently (Facebook uses 'page_access_token').
     */
    protected string $tokenKey = 'access_token';

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get($this->tokenKey);
    }

    /**
     * The credential keys publish(), resume() and comments need, as
     * key => human description (shown in the Misconfigured message and the
     * README table). A key may list alternatives separated by '|'
     * ('page_access_token|access_token'): any one of them satisfies it.
     *
     * @return array<string, string>
     */
    protected function requiredCredentials(): array
    {
        return [$this->tokenKey => 'access token'];
    }

    /**
     * Fail fast, before any request, when a required credential is missing:
     * a PermanentException(Misconfigured) naming the missing keys, instead of a
     * request sent with 'Bearer ' and a platform 401 that looks like a revoked
     * token. Call it at the start of publish(), resume() and the comment
     * methods, never in validate() (which runs without credentials).
     */
    protected function assertCredentials(PreparedPost $post): void
    {
        $missing = [];

        foreach ($this->requiredCredentials() as $key => $description) {
            if (is_int($key)) {
                [$key, $description] = [(string) $description, ''];
            }

            $alternatives = array_filter(array_map('trim', explode('|', $key)), static fn ($alt) => $alt !== '');
            $present = false;

            foreach ($alternatives as $alternative) {
                if ($post->credentials->filled($alternative)) {
                    $present = true;
                    break;
                }
            }

            if (! $present) {
                $missing[$key] = $description;
            }
        }

        if ($missing === []) {
            return;
        }

        $list = implode(', ', array_map(
            static fn ($key, $description) => $description !== '' ? "{$key} ({$description})" : $key,
            array_keys($missing),
            $missing,
        ));

        $message = "The {$this->platformLabel()} credentials are missing: {$list}.";

        if ($post->credentials->isReference()) {
            $message .= " They were dispatched as a reference ('{$post->credentials->reference()}'); bind a CredentialsResolver (config social.credentials_resolver) that returns the real values.";
        }

        throw new PermanentException(
            $message,
            $this->platform(),
            FailureReason::Misconfigured,
            ['missing_credentials' => array_keys($missing)],
        );
    }

    /**
     * Refuse to spend a quota-costing call on a token that expires within
     * $margin seconds (credential 'expires_at'; unknown expiry passes):
     *
     * - no CredentialsResolver bound: PermanentException(InvalidToken) with
     *   token_expired = true, since retrying would replay the same token;
     * - a resolver is bound: TemporaryException(InvalidToken, retryAfter 0) with
     *   reresolve = true, so the next run picks up fresh credentials.
     */
    protected function assertTokenFresh(PreparedPost $post, int $margin = 60): void
    {
        $credentials = $post->credentials;

        if (! $credentials->expiresWithin($margin)) {
            return;
        }

        $expiresAt = $credentials->expiresAt()?->format(DATE_ATOM);
        $context = ['token_expired' => true, 'expires_at' => $expiresAt];

        if ($this->hasCredentialsResolver()) {
            throw new TemporaryException(
                "The {$this->platformLabel()} token expires at {$expiresAt}; retrying with re-resolved credentials.",
                $this->platform(),
                FailureReason::InvalidToken,
                0,
                $context + ['reresolve' => true],
            );
        }

        throw new PermanentException(
            "The {$this->platformLabel()} token expired (or expires within {$margin}s) at {$expiresAt}; refresh it before dispatching, or bind a CredentialsResolver.",
            $this->platform(),
            FailureReason::InvalidToken,
            $context,
        );
    }

    /** True when the app bound its own CredentialsResolver (a re-resolve can return a new token). */
    protected function hasCredentialsResolver(): bool
    {
        return IdentityCredentialsResolver::customResolverBound();
    }

    /**
     * Credential keys that identify the account, most significant first. The
     * first key is the identity; later keys refine it (Pinterest: board_id, then
     * board_section_id). First-party whitelists: Meta/Threads account_id,
     * LinkedIn author, X user_id, TikTok open_id, YouTube channel_id, Pinterest
     * board_id + board_section_id, Bluesky did. Never list volatile keys
     * (tokens, expires_at, scopes): the identity must not change on refresh.
     *
     * @return list<string>
     */
    protected function identityKeys(): array
    {
        return ['account_id'];
    }

    /**
     * The identityKeys() of this driver, read-only (e.g. to name the identity
     * credential in a warning).
     *
     * @return list<string>
     */
    public function identityCredentialKeys(): array
    {
        return array_values(array_filter($this->identityKeys(), 'is_string'));
    }

    /**
     * A stable, non-secret identity for the account behind $credentials, used in
     * idempotency keys and CredentialsRejected. In order:
     *
     * 1. the identityKeys() values, joined by ':' (when the first one is set);
     * 2. a generic 'account' credential;
     * 3. the Credentials::ref() reference, as 'ref:<key>';
     * 4. '' when nothing identifies the account.
     *
     * Never the token: YouTube, X and TikTok replace theirs within hours, and
     * a key derived from it would change on every refresh, so a re-dispatch
     * with the same ->idempotencyKey() would post again and outcome() /
     * reconcile() would not find the publish. With '' the key is scoped by
     * platform and correlation id only, exactly as in v1.0; pass the identity
     * credential (channel_id, user_id, open_id...) or 'account' to keep two
     * accounts that share one ->idempotencyKey() apart.
     */
    public function accountKey(Credentials $credentials): string
    {
        $keys = $this->identityKeys();

        if ($keys !== [] && $this->scalarCredential($credentials, $keys[0]) !== null) {
            $parts = [];

            foreach ($keys as $key) {
                $value = $this->scalarCredential($credentials, $key);

                if ($value !== null) {
                    $parts[] = $value;
                }
            }

            return implode(':', $parts);
        }

        $account = $this->scalarCredential($credentials, 'account');

        if ($account !== null) {
            return $account;
        }

        if ($credentials->reference() !== null) {
            return 'ref:'.$credentials->reference();
        }

        return '';
    }

    // --- Scopes -----------------------------------------------------------------

    /**
     * The OAuth scopes a feature needs, in conjunctive normal form: a list of
     * any-of groups, every group required, e.g.
     * [['pages_manage_engagement'], ['pages_read_user_content']], or
     * [['instagram_content_publish', 'instagram_business_content_publish']].
     * May depend on the credentials (login type, person vs organisation).
     * Default: nothing known.
     *
     * @return array<int, string|array<int, string>>
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return [];
    }

    /**
     * Public view of requiredScopes(), normalised, for connect flows that need
     * to know what to request (Poster::requiredScopes()).
     *
     * @return list<list<string>>
     */
    public function scopesFor(Feature $feature, ?Credentials $credentials = null): array
    {
        return Credentials::normaliseScopeGroups($this->requiredScopes($feature, $credentials ?? new Credentials));
    }

    /**
     * The required scope groups the credentials are known not to grant, or null
     * when they do not list their scopes (unknown).
     *
     * @return list<list<string>>|null
     */
    public function missingScopes(Feature $feature, Credentials $credentials): ?array
    {
        return $credentials->missingScopes($this->scopesFor($feature, $credentials));
    }

    // --- HTTP -------------------------------------------------------------------

    /**
     * The default HTTP client. Starts from the platform's authorizer when one is
     * configured in config('social.authorizers.<platform>') (DPoP, signed or
     * app-supplied clients), else a bearer-token request; then applies a 10s
     * connect timeout and apiConfig('timeout', config('social.http_timeout', 60)).
     * Drivers that need extra headers, a base URL or a longer upload timeout
     * decorate this rather than rebuild it, so every request is authorized in
     * one place.
     *
     * @return PendingRequest
     */
    protected function http(PreparedPost $post)
    {
        $authorizer = $this->configuredAuthorizer();

        $request = $authorizer !== null
            ? $authorizer->request($this->platform(), $post->credentials)
            : Http::withToken($this->token($post));

        return $request
            ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout((int) $this->apiConfig('timeout', config('social.http_timeout', 60)));
    }

    /**
     * The request authorizer configured for this platform, resolved from the
     * container, or null for plain bearer auth.
     *
     * @throws PermanentException When the configured value is not an AuthorizesRequests (Misconfigured).
     */
    private function configuredAuthorizer(): ?AuthorizesRequests
    {
        $configured = config('social.authorizers.'.$this->platform()->value);

        if ($configured === null || $configured === '' || $configured === false) {
            return null;
        }

        try {
            $authorizer = is_string($configured) ? app($configured) : $configured;
        } catch (\Throwable $e) {
            throw new PermanentException(
                "The {$this->platformLabel()} request authorizer could not be built: {$e->getMessage()}",
                $this->platform(),
                FailureReason::Misconfigured,
                ['authorizer' => is_string($configured) ? $configured : get_debug_type($configured)],
                $e,
            );
        }

        if (! $authorizer instanceof AuthorizesRequests) {
            throw new PermanentException(
                "config('social.authorizers.{$this->platform()->value}') must name a class implementing ".AuthorizesRequests::class.'.',
                $this->platform(),
                FailureReason::Misconfigured,
                ['authorizer' => is_string($configured) ? $configured : get_debug_type($configured)],
            );
        }

        return $authorizer;
    }

    /** Whether the platform considers this response a success. */
    protected function ok($response): bool
    {
        return $response->successful();
    }

    /**
     * Return the post's options only when they are the given driver-specific type.
     * Guards against an options object set for another platform in a fan-out.
     *
     * @template T of PlatformOptions
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function optionsOf(PreparedPost $post, string $class): ?PlatformOptions
    {
        $options = $post->options();

        return $options instanceof $class ? $options : null;
    }

    /**
     * Read a per-platform API setting (e.g. the Graph version or LinkedIn version)
     * from config('social.api.<platform>.<key>'), falling back to the driver's
     * baked-in default so nothing breaks when the config is not published.
     */
    protected function apiConfig(string $key, mixed $default = null): mixed
    {
        return config("social.api.{$this->platform()->value}.{$key}", $default);
    }

    /**
     * The raw passthrough payload for THIS platform, or [] if none applies.
     * Scoped by platform() so an options object set for another platform in a
     * fan-out can never leak its params into this driver's request.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(PreparedPost $post): array
    {
        $options = $post->options();

        return $options !== null && $options->platform() === $this->platform()
            ? $options->extra()
            : [];
    }

    /**
     * Merge the raw passthrough into a create request. Driver-computed keys win
     * on collision, so the escape hatch can add fields but never silently
     * override what the driver already decided (media_type, urls, ids, ...).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function mergeExtra(PreparedPost $post, array $params): array
    {
        return array_merge($this->extraPayload($post), $params);
    }

    // --- Errors -----------------------------------------------------------------

    /**
     * Final entry point for turning a failed HTTP response into an exception.
     * Drivers keep calling $this->mapError($response, ...$context); this:
     *
     * 1. runs the driver's classifyError($response, ...$context). The extra
     *    arguments are forwarded, so a driver may declare
     *    classifyError($response, string $operation = 'post');
     * 2. adds http_status and the error identifiers (code, subcode, trace_id...)
     *    to the exception context (keys the driver set win);
     * 3. when the app bound its own CredentialsResolver, turns a non-terminal
     *    InvalidToken into a TemporaryException(retryAfter 0, reresolve = true)
     *    wrapping it, so the job retries once with fresh credentials (the job
     *    makes a repeat permanent by rethrowing getPrevious());
     * 4. logs it (reportError()).
     *
     * @param  mixed  $response
     */
    final protected function mapError($response, ...$args): TemporaryException|PermanentException
    {
        $exception = $this->classifyError($response, ...$args);

        $identifiers = $this->errorIdentifiers($response);
        unset($identifiers['message']);

        if (isset($identifiers['trace'])) {
            $identifiers['trace_id'] = $identifiers['trace'];
            unset($identifiers['trace']);
        }

        $exception->addContext(['http_status' => $response->status()] + $identifiers);

        if ($exception instanceof PermanentException && $this->shouldReresolve($exception, $response)) {
            $exception = new TemporaryException(
                $exception->getMessage(),
                $exception->platform ?? $this->platform(),
                $exception->reason,
                0,
                ['reresolve' => true] + $exception->context,
                $exception,
            );
        }

        $this->reportError($response, $exception);

        return $exception;
    }

    /**
     * Whether a rejected token deserves one retry with re-resolved credentials:
     * the app bound its own CredentialsResolver, the reason is InvalidToken, the
     * rejection is not terminal, and the platform said 401 (or the classifier
     * flagged it 'reresolvable' / 'token_expired', as the Meta table does for a
     * non-terminal 190).
     *
     * @param  mixed  $response
     */
    protected function shouldReresolve(PermanentException $exception, $response): bool
    {
        if ($exception->reason !== FailureReason::InvalidToken || $exception->isTerminal()) {
            return false;
        }

        $signalled = (int) $response->status() === 401
            || ($exception->context['reresolvable'] ?? false) === true
            || ($exception->context['token_expired'] ?? false) === true;

        return $signalled && $this->hasCredentialsResolver();
    }

    /**
     * Platform-specific classification of an error response into a Temporary or
     * Permanent exception with a FailureReason. Drivers override this with their
     * code/subcode tables and end with `return parent::classifyError($response)`
     * for anything unmapped. The default is the safe fallback by HTTP status:
     *
     * - 401 InvalidToken, 403 InsufficientPermissions, 404 NotFound (Permanent);
     * - 429 RateLimited, and 408 / 425 / 5xx ServerError (Temporary), waiting
     *   what Retry-After / x-rate-limit-reset / ratelimit-reset ask for;
     * - anything else Unknown, with the HTTP status in the message.
     *
     * The message is the platform's own (error.error_user_msg, error.message,
     * message, detail, title...), else a generic line. mapError() forwards any
     * extra arguments it was given; this default ignores them.
     *
     * @param  mixed  $response
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $detail = $this->errorMessage($response);
        $platform = $this->platform();
        $label = $this->platformLabel();
        $context = ['status' => $status, 'http_status' => $status];

        return match (true) {
            $status === 401 => new PermanentException(
                $detail ?? "{$label} rejected the access token (HTTP 401); the account must be reconnected.",
                $platform,
                FailureReason::InvalidToken,
                $context,
            ),
            $status === 403 => new PermanentException(
                $detail ?? "{$label} refused the request (HTTP 403); the token may lack a required permission.",
                $platform,
                FailureReason::InsufficientPermissions,
                $context,
            ),
            $status === 404 => new PermanentException(
                $detail ?? "{$label} could not find the requested resource (HTTP 404).",
                $platform,
                FailureReason::NotFound,
                $context,
            ),
            $status === 429 => new TemporaryException(
                $detail ?? "{$label} rate limit reached (HTTP 429).",
                $platform,
                FailureReason::RateLimited,
                RetryAfter::fromResponse($response),
                $context,
            ),
            $status === 408 || $status === 425 || $status >= 500 => new TemporaryException(
                $detail ?? "{$label} is temporarily unavailable (HTTP {$status}).",
                $platform,
                FailureReason::ServerError,
                RetryAfter::fromResponse($response),
                $context,
            ),
            default => new PermanentException(
                $detail !== null ? "{$detail} (HTTP {$status})" : "{$label} request failed with HTTP {$status}.",
                $platform,
                FailureReason::Unknown,
                $context,
            ),
        };
    }

    /**
     * The platform's own error message from the usual shapes: Meta
     * error.error_user_msg / error.message, top-level message (Pinterest,
     * LinkedIn, XRPC), detail / title (X problem+json), OAuth error_description,
     * errors[0], or a bare string 'error' code. Null when there is none.
     *
     * @param  mixed  $response
     */
    private function errorMessage($response): ?string
    {
        $body = $this->decodedBody($response);

        if ($body === null) {
            return null;
        }

        foreach (['error.error_user_msg', 'error.message', 'message', 'detail', 'title', 'error_description', 'errors.0.message', 'errors.0.detail'] as $path) {
            $value = data_get($body, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $error = $body['error'] ?? null;

        return is_string($error) && trim($error) !== '' ? trim($error) : null;
    }

    /**
     * The identifying fields of an error body, across platform shapes: code
     * (error.code, errors[0].code, top-level code, or a string 'error'),
     * subcode, type, problem_type (X), reason (Google), message, LinkedIn
     * service_error_code and input_errors, and the trace id.
     *
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    private function errorIdentifiers($response): array
    {
        $body = $this->decodedBody($response) ?? [];
        $topError = $body['error'] ?? null;
        $topCode = $body['code'] ?? null;
        $topType = $body['type'] ?? null;

        $message = null;

        foreach (['error.message', 'errors.0.detail', 'errors.0.message', 'message', 'detail', 'title'] as $path) {
            $value = data_get($body, $path);

            if (is_string($value) && trim($value) !== '') {
                $message = trim($value);
                break;
            }
        }

        $inputErrors = data_get($body, 'errorDetails.inputErrors.*.code');
        $inputErrors = is_array($inputErrors)
            ? array_values(array_filter($inputErrors, static fn ($code) => is_scalar($code) && $code !== ''))
            : [];

        return array_filter([
            'code' => data_get($body, 'error.code')
                ?? data_get($body, 'errors.0.code')
                ?? (is_scalar($topCode) ? $topCode : null)
                ?? (is_string($topError) ? $topError : null),
            'subcode' => data_get($body, 'error.error_subcode'),
            'type' => data_get($body, 'error.type'),
            'problem_type' => is_string($topType) ? $topType : null,
            'reason' => data_get($body, 'error.errors.0.reason') ?? data_get($body, 'errors.0.reason'),
            'message' => $message,
            'service_error_code' => data_get($body, 'serviceErrorCode'),
            'input_errors' => $inputErrors,
            'trace' => data_get($body, 'error.fbtrace_id') ?? data_get($body, 'error.log_id') ?? data_get($body, 'log_id'),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Log a failed response with as much identifying context as possible. The
     * `unmapped` flag and the `identifiers` block (code, subcode, message, ...)
     * make it quick to find an error that fell through to FailureReason::Unknown
     * and add a specific mapping to the driver's classifyError(). Temporary
     * classifications log at warning level, permanent ones at error. Tokens never
     * reach the log: Authorization/Cookie headers are dropped and token-like keys
     * and query parameters are redacted. Disable with config('social.error_logging')
     * or route it with config('social.error_log_channel').
     *
     * @param  mixed  $response
     */
    protected function reportError($response, TemporaryException|PermanentException $exception): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        // Logging must never mask the real error.
        try {
            $body = $this->decodedBody($response);

            $context = [
                'platform' => $this->platform()->value,
                'driver' => static::class,
                'unmapped' => $exception->reason === FailureReason::Unknown,
                'classified_as' => $exception instanceof TemporaryException ? 'temporary' : 'permanent',
                'failure_reason' => $exception->reason->value,
                'retry_after' => $exception instanceof TemporaryException ? $exception->retryAfter : null,
                'http_status' => $response->status(),
                'identifiers' => $this->redactForLog($this->errorIdentifiers($response)),
                'response_body' => $this->redactForLog($body ?? $this->truncate((string) $response->body())),
                'response_headers' => $this->safeHeaders((array) $response->headers()),
                'exception_context' => $this->redactForLog($exception->context),
            ];

            $message = '[SocialPoster] '.$this->platform()->value.' API error: '.$this->redactString($exception->getMessage());
            $temporary = $exception instanceof TemporaryException;
            $channel = config('social.error_log_channel');

            if ($channel) {
                $logger = Log::channel($channel);
                $temporary ? $logger->warning($message, $context) : $logger->error($message, $context);
            } else {
                $temporary ? Log::warning($message, $context) : Log::error($message, $context);
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    // --- Internals --------------------------------------------------------------

    /** Human name for messages ('LinkedIn', 'TikTok', 'YouTube'...). */
    private function platformLabel(): string
    {
        return match ($this->platform()) {
            Platform::LinkedIn => 'LinkedIn',
            Platform::TikTok => 'TikTok',
            Platform::YouTube => 'YouTube',
            Platform::X => 'X',
            default => ucfirst($this->platform()->value),
        };
    }

    /**
     * The decoded JSON body as an array, or null for an empty/non-JSON body.
     *
     * @param  mixed  $response
     * @return array<mixed>|null
     */
    private function decodedBody($response): ?array
    {
        try {
            $body = $response->json();
        } catch (\Throwable) {
            return null;
        }

        return is_array($body) ? $body : null;
    }

    /**
     * Strip secrets from a value before it is logged or put in a context:
     * token-like keys are replaced by a placeholder, and access_token=, key=,
     * signature= query parameters and Bearer/DPoP tokens inside strings are
     * redacted (Graph paging links and Guzzle messages carry them).
     */
    private function redactForLog(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value) || $depth > 8) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            // Only strings and arrays can hold a secret; flags such as
            // token_expired (bool) stay readable.
            $secret = is_string($key)
                && Credentials::isSecretKey($key)
                && ((is_string($item) && $item !== '') || (is_array($item) && $item !== []));

            $redacted[$key] = $secret ? Credentials::REDACTED : $this->redactForLog($item, $depth + 1);
        }

        return $redacted;
    }

    /** Redact token query parameters and Bearer/DPoP credentials inside a string. */
    private function redactString(string $value): string
    {
        $value = (string) preg_replace('/((?:access_token|refresh_token|client_secret|token|key|signature|sig)=)[^&\s"\']+/i', '$1[redacted]', $value);

        // A credential after the scheme, quoted or not, but not a challenge
        // parameter such as WWW-Authenticate's `Bearer error="invalid_token"`
        // (a short alphabetic name followed by `="`; real tokens carry digits,
        // dots or more length).
        return (string) preg_replace('/\b(Bearer|DPoP)\s+(?![A-Za-z][A-Za-z_-]{0,31}=")[A-Za-z0-9\-._~+\/]+=*/', '$1 [redacted]', $value);
    }

    /**
     * Response headers without credentials-bearing ones, values redacted.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function safeHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $name => $values) {
            if (in_array(strtolower((string) $name), self::SECRET_HEADERS, true)) {
                continue;
            }

            $safe[$name] = $this->redactForLog($values);
        }

        return $safe;
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_LOGGED_BODY
            ? mb_substr($text, 0, self::MAX_LOGGED_BODY).'... [truncated]'
            : $text;
    }

    /** A credential as a trimmed string when it is a non-blank scalar, else null. */
    private function scalarCredential(Credentials $credentials, string $key): ?string
    {
        $value = $credentials->get($key);

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
