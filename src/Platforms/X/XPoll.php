<?php

namespace SocialPoster\Platforms\X;

/**
 * A poll attached to an X post (POST /2/tweets poll): 2 to 4 options of at
 * most 25 characters each, open for 5 minutes to 7 days. X refuses a poll on a
 * post that also carries media or quotes another post.
 */
final class XPoll
{
    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 4;

    public const MAX_OPTION_LENGTH = 25;

    public const MIN_DURATION_MINUTES = 5;

    public const MAX_DURATION_MINUTES = 10080;

    /** @var list<string> */
    public readonly array $options;

    /**
     * @param  array<int, string>  $options  The choices, in display order.
     * @param  int  $durationMinutes  How long the poll stays open (default one day).
     */
    public function __construct(
        array $options,
        public readonly int $durationMinutes = 1440,
    ) {
        $this->options = array_values(array_map(static fn ($option) => (string) $option, $options));
    }

    /** @param  array<int, string>  $options */
    public static function make(array $options, int $durationMinutes = 1440): self
    {
        return new self($options, $durationMinutes);
    }

    /**
     * The poll as POST /2/tweets expects it.
     *
     * @return array{options: list<string>, duration_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'options' => array_map('trim', $this->options),
            'duration_minutes' => $this->durationMinutes,
        ];
    }

    /**
     * Why X would refuse this poll (empty when it is valid).
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];
        $count = count($this->options);

        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            $errors[] = 'An X poll needs '.self::MIN_OPTIONS.' to '.self::MAX_OPTIONS." options, got {$count}.";
        }

        foreach ($this->options as $index => $option) {
            $length = mb_strlen(trim($option));

            if ($length === 0) {
                $errors[] = 'Poll option #'.($index + 1).' is empty.';
            } elseif ($length > self::MAX_OPTION_LENGTH) {
                $errors[] = 'Poll option #'.($index + 1).' is '.$length.' characters long; X allows '.self::MAX_OPTION_LENGTH.'.';
            }
        }

        if ($this->durationMinutes < self::MIN_DURATION_MINUTES || $this->durationMinutes > self::MAX_DURATION_MINUTES) {
            $errors[] = 'An X poll must stay open between '.self::MIN_DURATION_MINUTES.' and '.self::MAX_DURATION_MINUTES." minutes (7 days), got {$this->durationMinutes}.";
        }

        return $errors;
    }
}
