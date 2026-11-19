<?php

namespace SocialPoster\Platforms\LinkedIn;

/**
 * An organic LinkedIn poll (Posts API content.poll): a question, two to four
 * options and how long voting stays open. The post's caption is the text above
 * the poll. A poll cannot be combined with media, a link card or a reshare.
 *
 * Limits are LinkedIn's documented ones: question up to 140 characters, each
 * option up to 30, durations ONE_DAY, THREE_DAYS, SEVEN_DAYS or FOURTEEN_DAYS.
 */
final class LinkedInPoll
{
    public const ONE_DAY = 'ONE_DAY';

    public const THREE_DAYS = 'THREE_DAYS';

    public const SEVEN_DAYS = 'SEVEN_DAYS';

    public const FOURTEEN_DAYS = 'FOURTEEN_DAYS';

    public const DURATIONS = [self::ONE_DAY, self::THREE_DAYS, self::SEVEN_DAYS, self::FOURTEEN_DAYS];

    public const MAX_QUESTION = 140;

    public const MAX_OPTION = 30;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 4;

    public readonly string $question;

    /** @var list<string> */
    public readonly array $options;

    public readonly string $duration;

    /** @param array<int, string> $options */
    public function __construct(string $question, array $options, string $duration = self::THREE_DAYS)
    {
        $this->question = trim($question);
        $this->options = array_values(array_map(static fn ($option) => trim((string) $option), $options));
        $this->duration = strtoupper(trim($duration));
    }

    /** @param array<int, string> $options */
    public static function make(string $question, array $options, string $duration = self::THREE_DAYS): self
    {
        return new self($question, $options, $duration);
    }

    /**
     * Build from ['question' => ..., 'options' => [...], 'duration' => ...].
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = $data['options'] ?? [];

        return new self(
            is_scalar($data['question'] ?? null) ? (string) $data['question'] : '',
            is_array($options) ? array_values(array_map(static fn ($o) => is_scalar($o) ? (string) $o : '', $options)) : [],
            is_scalar($data['duration'] ?? null) ? (string) $data['duration'] : self::THREE_DAYS,
        );
    }

    /**
     * Why LinkedIn would refuse this poll (empty when it is valid).
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];

        if ($this->question === '') {
            $errors[] = 'The poll needs a question.';
        } elseif (mb_strlen($this->question) > self::MAX_QUESTION) {
            $errors[] = 'The poll question exceeds '.self::MAX_QUESTION.' characters.';
        }

        $count = count($this->options);

        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            $errors[] = 'A poll needs '.self::MIN_OPTIONS.' to '.self::MAX_OPTIONS." options, got {$count}.";
        }

        foreach ($this->options as $index => $option) {
            if ($option === '') {
                $errors[] = 'Poll option #'.($index + 1).' is empty.';
            } elseif (mb_strlen($option) > self::MAX_OPTION) {
                $errors[] = 'Poll option #'.($index + 1).' exceeds '.self::MAX_OPTION.' characters.';
            }
        }

        if (count(array_unique(array_map('mb_strtolower', $this->options))) !== $count) {
            $errors[] = 'Poll options must be different from each other.';
        }

        if (! in_array($this->duration, self::DURATIONS, true)) {
            $errors[] = 'The poll duration must be one of '.implode(', ', self::DURATIONS).'.';
        }

        return $errors;
    }

    /**
     * The Posts API content block.
     *
     * @return array{poll: array{question: string, options: list<array{text: string}>, settings: array{duration: string}}}
     */
    public function toContent(): array
    {
        return [
            'poll' => [
                'question' => $this->question,
                'options' => array_map(static fn (string $text) => ['text' => $text], $this->options),
                'settings' => ['duration' => $this->duration],
            ],
        ];
    }
}
