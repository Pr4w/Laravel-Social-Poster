<?php

namespace SocialPoster\Platforms\Threads;

use Illuminate\Support\MessageBag;

/**
 * A poll attached to a Threads text post (the container's poll_attachment
 * field): two to four options of 1 to 25 characters each, sent as option_a ..
 * option_d. Threads only takes polls on TEXT posts, so the driver rejects one
 * on a post with media.
 */
final class ThreadsPoll
{
    /** Threads' documented limit per option (characters, after trimming). */
    public const MAX_OPTION_LENGTH = 25;

    public function __construct(
        public readonly string $optionA,
        public readonly string $optionB,
        public readonly ?string $optionC = null,
        public readonly ?string $optionD = null,
    ) {}

    /**
     * The options in order, blanks dropped.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_values(array_filter(
            array_map(static fn (?string $option) => trim((string) $option), [$this->optionA, $this->optionB, $this->optionC, $this->optionD]),
            static fn (string $option) => $option !== '',
        ));
    }

    /**
     * The poll_attachment object Threads expects.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $poll = [];

        foreach (['option_a' => $this->optionA, 'option_b' => $this->optionB, 'option_c' => $this->optionC, 'option_d' => $this->optionD] as $key => $option) {
            if ($option !== null && trim($option) !== '') {
                $poll[$key] = trim($option);
            }
        }

        return $poll;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if (trim($this->optionA) === '' || trim($this->optionB) === '') {
            $errors->add('options.poll_attachment', 'A Threads poll needs at least two non-empty options (option A and option B).');
        }

        if (($this->optionD !== null && trim($this->optionD) !== '') && ($this->optionC === null || trim($this->optionC) === '')) {
            $errors->add('options.poll_attachment', 'A Threads poll option D needs an option C.');
        }

        // Caught here, before dispatch: Threads refuses the whole container otherwise.
        foreach (['A' => $this->optionA, 'B' => $this->optionB, 'C' => $this->optionC, 'D' => $this->optionD] as $letter => $option) {
            $length = mb_strlen(trim((string) $option));

            if ($length > self::MAX_OPTION_LENGTH) {
                $errors->add('options.poll_attachment', "Threads poll option {$letter} must be at most ".self::MAX_OPTION_LENGTH." characters long (got {$length}).");
            }
        }

        $options = array_map('mb_strtolower', $this->options());

        if (count($options) !== count(array_unique($options))) {
            $errors->add('options.poll_attachment', 'Threads poll options must be different from each other.');
        }

        return $errors;
    }
}
