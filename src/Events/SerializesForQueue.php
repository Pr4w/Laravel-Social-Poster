<?php

namespace SocialPoster\Events;

/**
 * Lets an event travel through the queue (a listener implementing ShouldQueue)
 * whatever it carries, and lets a payload queued by an older release unserialize
 * after an upgrade with the properties added since set to their defaults.
 *
 * @see QueueSafe
 */
trait SerializesForQueue
{
    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        $data = [];

        foreach ((array) $this as $key => $value) {
            $data[$key] = QueueSafe::value($value);
        }

        return $data;
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        QueueSafe::restore($this, $data);
    }
}
