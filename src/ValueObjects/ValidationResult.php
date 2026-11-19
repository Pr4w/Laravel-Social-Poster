<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\MessageBag;
use JsonSerializable;
use SocialPoster\Enums\Platform;

/**
 * The return value of the validate() path. Never thrown, so a frontend can
 * render errors without try/catch. Arrayable + JsonSerializable so it drops
 * straight into an Inertia response.
 *
 * Warnings never fail the result: they flag something that will publish but
 * may not behave as expected (e.g. 'idempotency': the idempotency key cannot
 * keep two accounts apart because the credentials name no account).
 *
 * @implements Arrayable<string, mixed>
 */
final class ValidationResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly Platform $platform,
        public readonly MessageBag $errors,
        public readonly MessageBag $warnings = new MessageBag,
    ) {}

    /**
     * A result carrying a single error, e.g. for a platform whose driver is
     * not registered or whose validation could not run.
     */
    public static function withError(Platform $platform, string $message, string $field = 'platform'): self
    {
        return new self($platform, new MessageBag([$field => [$message]]));
    }

    public function passes(): bool
    {
        return $this->errors->isEmpty();
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    public function errors(): MessageBag
    {
        return $this->errors;
    }

    /** Non-blocking notes; passes() ignores them. */
    public function warnings(): MessageBag
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings->isNotEmpty();
    }

    /**
     * 'warnings' is present only when there are some, so the v1.0 shape is
     * unchanged otherwise.
     *
     * @return array{platform: string, passes: bool, errors: array<string, array<int, string>>, warnings?: array<string, array<int, string>>}
     */
    public function toArray(): array
    {
        $array = [
            'platform' => $this->platform->value,
            'passes' => $this->passes(),
            'errors' => $this->errors->toArray(),
        ];

        if ($this->hasWarnings()) {
            $array['warnings'] = $this->warnings->toArray();
        }

        return $array;
    }

    /** @return array{platform: string, passes: bool, errors: array<string, array<int, string>>, warnings?: array<string, array<int, string>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
