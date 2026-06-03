<?php

namespace SocialPoster\Platforms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Concerns\ValidatesAgainstCapabilities;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
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
 */
abstract class AbstractPlatform implements SocialPlatform
{
    use ValidatesAgainstCapabilities;

    public function __construct(
        protected ?MediaInspector $inspector = null,
        protected ?MediaGateway $gateway = null,
    ) {}

    abstract public function platform(): Platform;

    abstract public function capabilities(): Capabilities;

    public function validate(PreparedPost $post): MessageBag
    {
        $errors = new MessageBag();
        $caps = $this->rulesFor($post);

        $this->validateAgainstCapabilities($post, $caps, $errors);
        $this->validatePlatformRules($post, $errors);
        $this->validateMediaTransfer($post, $errors);

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

    /** Optional content transformation into the platform payload. */
    protected function prepare(PreparedPost $post): array
    {
        return [];
    }

    protected function published(PostResult $result): Published
    {
        return PublishOutcome::published($result);
    }

    /** @param array<string, mixed> $state */
    protected function pending(array $state, int $recheckAfter = 30): Pending
    {
        return PublishOutcome::pending($state, $recheckAfter);
    }

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

    /**
     * Final entry point for turning a failed HTTP response into an exception.
     * Drivers keep calling $this->mapError($response); this runs the driver's
     * classifyError() mapping and then logs the error with full context, so an
     * unmapped code or subcode is easy to spot in the logs and add to the driver.
     *
     * @param mixed $response
     */
    final protected function mapError($response, ...$args): TemporaryException|PermanentException
    {
        $exception = $this->classifyError($response, ...$args);

        $this->reportError($response, $exception);

        return $exception;
    }

    /**
     * Platform-specific classification of an error response into a Temporary or
     * Permanent exception with a FailureReason. Drivers override this with their
     * code/subcode tables; the default treats anything unrecognised as a
     * permanent unknown error.
     *
     * @param mixed $response
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $message = (string) (data_get($response->json(), 'error.message') ?: 'Request failed.');

        return new PermanentException($message, $this->platform(), FailureReason::Unknown, ['status' => $response->status()]);
    }

    /**
     * Log a failed response with as much identifying context as possible. The
     * `unmapped` flag and the `identifiers` block (code, subcode, message, ...)
     * make it quick to find an error that fell through to FailureReason::Unknown
     * and add a specific mapping to the driver's classifyError(). Disable with
     * config('social.error_logging') or route it with config('social.error_log_channel').
     *
     * @param mixed $response
     */
    protected function reportError($response, TemporaryException|PermanentException $exception): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        $body = null;

        try {
            $body = $response->json();
        } catch (\Throwable) {
            // Non-JSON body; fall back to the raw string below.
        }

        // Pull the usual identifying fields from the various platform error shapes.
        $identifiers = array_filter([
            'code' => data_get($body, 'error.code') ?? data_get($body, 'errors.0.code'),
            'subcode' => data_get($body, 'error.error_subcode'),
            'type' => data_get($body, 'error.type'),
            'reason' => data_get($body, 'error.errors.0.reason') ?? data_get($body, 'errors.0.reason'),
            'message' => data_get($body, 'error.message')
                ?? data_get($body, 'errors.0.detail')
                ?? data_get($body, 'errors.0.message'),
            'service_error_code' => data_get($body, 'serviceErrorCode'),
            'trace' => data_get($body, 'error.fbtrace_id') ?? data_get($body, 'error.log_id') ?? data_get($body, 'log_id'),
        ], static fn ($value) => $value !== null && $value !== '');

        $context = [
            'platform' => $this->platform()->value,
            'driver' => static::class,
            'unmapped' => $exception->reason === FailureReason::Unknown,
            'classified_as' => $exception instanceof TemporaryException ? 'temporary' : 'permanent',
            'failure_reason' => $exception->reason->value,
            'retry_after' => $exception instanceof TemporaryException ? $exception->retryAfter : null,
            'http_status' => $response->status(),
            'identifiers' => $identifiers,
            'response_body' => $body ?? $response->body(),
            'response_headers' => $response->headers(),
            'exception_context' => $exception->context,
        ];

        $message = '[SocialPoster] '.$this->platform()->value.' API error: '.$exception->getMessage();

        // Logging must never mask the real error.
        try {
            if ($channel = config('social.error_log_channel')) {
                Log::channel($channel)->error($message, $context);
            } else {
                Log::error($message, $context);
            }
        } catch (\Throwable) {
            // ignore
        }
    }
}
