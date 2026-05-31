<?php

namespace SocialPoster\Contracts;

use Illuminate\Support\MessageBag;
use SocialPoster\Enums\Platform;

/**
 * Typed, per-platform options (Instagram story vs feed, LinkedIn escaping, etc.).
 *
 * Beyond the typed knobs each implementation exposes, every options object also
 * carries an escape-hatch payload via extra(): a flat map of raw key/values the
 * driver merges into its post-level create request. This is how platform params
 * we deliberately do not model (Instagram trial_params, X reply_settings, and so
 * on) reach the API without the package mirroring every vendor field. Anything in
 * extra() is unvalidated by design, and driver-computed keys win on collision.
 */
interface PlatformOptions
{
    public function platform(): Platform;

    /** @return array<string, mixed> Raw passthrough merged into the create request. */
    public function extra(): array;

    public function validate(): MessageBag;
}
