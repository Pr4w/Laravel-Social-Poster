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
 */
final class ValidationResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly Platform $platform,
        public readonly MessageBag $errors,
    ) {}

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

    public function toArray(): array
    {
        return [
            'platform' => $this->platform->value,
            'passes' => $this->passes(),
            'errors' => $this->errors->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
