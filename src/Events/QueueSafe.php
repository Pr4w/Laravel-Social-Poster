<?php

namespace SocialPoster\Events;

use Error;
use Exception;
use ReflectionClass;
use ReflectionProperty;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PreparedPost;
use Throwable;

/**
 * Serialisation helpers that keep events and results usable by queued listeners.
 *
 * Two things break a queued listener in practice:
 *
 * - An exception whose stack trace holds a Closure argument (any frame inside a
 *   Laravel pipeline when zend.exception_ignore_args is off, the CLI default)
 *   throws "Serialization of 'Closure' is not allowed" at dispatch time. When
 *   that happens the event carries a portable copy instead: same class, message,
 *   code, file, line, reason and serialisable context, trace frames without their
 *   arguments, and the previous chain copied the same way.
 * - A payload serialised by an older release lacks properties added since. PHP
 *   leaves those readonly promoted properties uninitialised, and the first read
 *   throws. restore() fills them from the constructor defaults.
 *
 * Exceptions are always copied, never queued as they are: a transport error in
 * the previous chain holds the HTTP request (Authorization header, token
 * query), a Response holds it too through its transfer stats, and stack frame
 * arguments hold the PreparedPost and its Credentials. The copy drops those
 * values (SENSITIVE) and every frame's arguments, so no token reaches the
 * jobs table, Redis, Horizon or failed_jobs through a queued listener.
 *
 * @internal
 */
final class QueueSafe
{
    /** Previous-chain depth copied before the rest is dropped. */
    private const MAX_DEPTH = 10;

    /** Values never copied out of an exception: they carry a request, a response or credentials. */
    private const SENSITIVE = [
        'Psr\Http\Message\MessageInterface',
        'Illuminate\Http\Client\Request',
        'Illuminate\Http\Client\Response',
        'Illuminate\Http\Client\PendingRequest',
        'GuzzleHttp\TransferStats',
        Credentials::class,
        PreparedPost::class,
    ];

    public static function canSerialize(mixed $value): bool
    {
        try {
            serialize($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The value itself when it serialises; otherwise a copy that does
     * (portable throwable, array without the offending entries, or null).
     */
    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof Throwable) {
            return self::throwable($value, $depth);
        }

        if (self::canSerialize($value)) {
            return $value;
        }

        return is_array($value) ? self::filterArray($value, $depth) : null;
    }

    /**
     * A portable copy of the exception, of the same class (so typed properties
     * such as `SocialPosterException $exception` still accept it): message,
     * code, file, line, trace frames without their arguments, the class's own
     * serialisable properties without requests, responses or credentials, and
     * the previous chain copied the same way.
     */
    public static function throwable(Throwable $e, int $depth = 0): Throwable
    {
        try {
            $class = new ReflectionClass($e);
            $copy = $class->newInstanceWithoutConstructor();
        } catch (Throwable) {
            // Only internal final classes refuse this, never a package exception,
            // so a generic stand-in is fine for the rare previous-chain entry.
            return new Exception(get_class($e).': '.$e->getMessage(), (int) $e->getCode());
        }

        for ($scope = $class; $scope !== false; $scope = $scope->getParentClass()) {
            foreach ($scope->getProperties() as $property) {
                if ($property->isStatic()
                    || $property->getDeclaringClass()->getName() !== $scope->getName()
                    || ! $property->isInitialized($e)) {
                    continue;
                }

                $value = $property->getValue($e);

                if ($property->getName() === 'trace' && in_array($scope->getName(), [Exception::class, Error::class], true)) {
                    $value = array_map(
                        static fn ($frame) => is_array($frame) ? array_diff_key($frame, ['args' => true]) : $frame,
                        (array) $value,
                    );
                } elseif ($value instanceof Throwable) {
                    $value = $depth < self::MAX_DEPTH ? self::throwable($value, $depth + 1) : null;
                } else {
                    $value = self::scrub($value, $depth);
                }

                self::assign($property, $copy, $value);
            }
        }

        return $copy;
    }

    /** Whether a value carries a request, a response or credentials. */
    public static function isSensitive(mixed $value): bool
    {
        if (! is_object($value)) {
            return false;
        }

        foreach (self::SENSITIVE as $class) {
            if ($value instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** An exception property value without sensitive objects, at any depth, and serialisable. */
    private static function scrub(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            return $depth < self::MAX_DEPTH ? self::throwable($value, $depth + 1) : null;
        }

        if (self::isSensitive($value)) {
            return null;
        }

        if (is_array($value)) {
            $kept = [];

            foreach ($value as $key => $item) {
                if (self::isSensitive($item)) {
                    continue;
                }

                if ($item instanceof Throwable || is_array($item)) {
                    if ($depth < self::MAX_DEPTH) {
                        $kept[$key] = self::scrub($item, $depth + 1);
                    }
                } elseif (self::canSerialize($item)) {
                    $kept[$key] = $item;
                }
            }

            return $kept;
        }

        return self::canSerialize($value) ? $value : null;
    }

    /**
     * Only scalars, nulls and arrays of them, at any depth. Used for error
     * summaries, which must stay serialisable and JSON-safe even when a driver
     * nests an object or closure inside its exception context.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function plain(array $values, int $depth = 0): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $kept[$key] = $value;
            } elseif (is_array($value) && $depth < self::MAX_DEPTH) {
                $kept[$key] = self::plain($value, $depth + 1);
            }
        }

        return $kept;
    }

    /**
     * An exception's errorSummary() with the event's platform filled in and the
     * context reduced to plain values.
     *
     * @return array{platform: ?string, reason: string, category: string, message: string, class: string, context: array<string, mixed>}
     */
    public static function summary(SocialPosterException $exception, Platform $platform): array
    {
        $summary = $exception->errorSummary();
        $summary['platform'] ??= $platform->value;
        $summary['context'] = self::plain($summary['context']);

        return $summary;
    }

    /**
     * Initialise $target from unserialised data, then give every promoted
     * constructor property the payload did not carry its declared default.
     * Accepts both plain keys and PHP's mangled private/protected keys, which is
     * what a payload written before __serialize() existed contains.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function restore(object $target, array $data): void
    {
        foreach ($data as $key => $value) {
            [$class, $name] = self::demangle($target, (string) $key);

            if ($class === null || ! property_exists($class, $name)) {
                continue;
            }

            $property = new ReflectionProperty($class, $name);

            if ($property->isStatic() || ($property->isReadOnly() && $property->isInitialized($target))) {
                continue;
            }

            self::assign($property, $target, $value);
        }

        self::fillDefaults($target);
    }

    /** Give uninitialised promoted properties their constructor default, up the class chain. */
    public static function fillDefaults(object $target): void
    {
        for ($class = new ReflectionClass($target); $class !== false; $class = $class->getParentClass()) {
            $constructor = $class->getConstructor();

            if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                if (! $parameter->isPromoted() || ! $parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $property = new ReflectionProperty($class->getName(), $parameter->getName());

                if (! $property->isInitialized($target)) {
                    self::assign($property, $target, $parameter->getDefaultValue());
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function filterArray(array $values, int $depth): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                $kept[$key] = self::throwable($value, $depth + 1);
            } elseif (self::canSerialize($value)) {
                $kept[$key] = $value;
            } elseif (is_array($value) && $depth < self::MAX_DEPTH) {
                $kept[$key] = self::filterArray($value, $depth + 1);
            }
        }

        return $kept;
    }

    /** @return array{0: ?string, 1: string} [declaring class or null when unusable, property name] */
    private static function demangle(object $target, string $key): array
    {
        if (! str_starts_with($key, "\0")) {
            return [$target::class, $key];
        }

        $parts = explode("\0", $key, 3);

        if (count($parts) !== 3) {
            return [null, $key];
        }

        return [$parts[1] === '*' ? $target::class : $parts[1], $parts[2]];
    }

    /** Set a property, skipping values its type refuses (a shape from another version). */
    private static function assign(ReflectionProperty $property, object $target, mixed $value): void
    {
        try {
            $property->setValue($target, $value);
        } catch (Throwable) {
            // Leave it to fillDefaults() or uninitialised; never break unserialize().
        }
    }
}
